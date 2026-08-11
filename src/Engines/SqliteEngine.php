<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Support\Facades\Log;
use Moaines\IllumiSearch\Contracts\Engine;
use Illuminate\Support\Str;
use Moaines\IllumiSearch\Concerns\HasOperatorProcessor;
use Moaines\IllumiSearch\Concerns\HasTenant;
use Moaines\IllumiSearch\Concerns\HasVocabSuggest;
use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Support\OperatorProcessor;
use Moaines\IllumiSearch\Text\HasDebugCollector;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\ConfigHelper;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;
use Moaines\IllumiSearch\Support\IllumiSearchHelper;
use Moaines\IllumiSearch\Support\OperatorRegistry;
use Moaines\IllumiSearch\Support\SearchCache;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Text\HasScoring;
use SQLite3;

class SqliteEngine implements Engine
{
    use HasScoring;
    use HasOperatorProcessor;
    use HasDebugCollector;
    use HasTenant;
    use HasTextHelpers;
    use HasVocabSuggest;
 
    private const META_TABLE = 'meta';

    private const CONFIG_TABLE = 'config';

    private ?SQLite3 $db = null;

    /** @var list<string> */
    protected array $supportedOperators = ['AND', 'OR', 'NOT'];

    /** @var list<string> */
    protected array $rawSupportedOperators = ['AND', 'OR', 'NOT'];

    protected bool $operatorsProbed = false;

    /** @var array<string, string> */
    private array $cachedSafeQueries = [];

    private int $maxCachedQueries = 1000;
    private bool $fts5Available = false;
    private bool $isRebuilding = false;
    private SearchCache $searchCache;
    private IllumiSearchConfig $illumiConfig;
    private ?\Moaines\IllumiSearch\Contracts\TextProcessor $textProcessor = null;

    public function __construct(
        private readonly string $databasePath,
        private readonly ?SnippetService $snippets = null,
        ?IllumiSearchConfig $illumiConfig = null,
        ?OperatorProcessor $operatorProcessor = null,
    ) {
        $this->searchCache = new SearchCache(dirname($databasePath));
        $this->illumiConfig = $illumiConfig ?? app(IllumiSearchConfig::class);
        $this->injectOperatorProcessor($operatorProcessor);
    }

    public function setRebuilding(bool $isRebuilding): void
    {
        $this->isRebuilding = $isRebuilding;
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    /**
     * @param  array<string, string>  $document
     * @return array<string, string>
     */
    protected function sanitizeDocumentKeys(array $document): array
    {
        $sanitized = [];
        foreach ($document as $key => $value) {
            $sanitized[$this->normalizeColumnName($key)] = $value;
        }

        return $sanitized;
    }

    private function normalizeColumnName(string $key): string
    {
        return IllumiSearchHelper::normalizeColumnName($key);
    }

    public function getDatabaseSize(): int
    {
        if (file_exists($this->databasePath)) {
            return filesize($this->databasePath);
        }

        return 0;
    }

    protected function db(): SQLite3
    {
        if ($this->db === null) {
            $this->db = new SQLite3($this->databasePath);

            $c = $this->illumiConfig;

            if (filter_var($c->sqliteWal(), FILTER_VALIDATE_BOOLEAN)) {
                $this->db->exec('PRAGMA journal_mode=WAL');
            }
            $this->db->exec('PRAGMA synchronous=' . $c->sqliteSynchronous());
            $this->db->exec('PRAGMA cache_size=' . $c->sqliteCacheSizeKb());
            $this->db->exec('PRAGMA temp_store=' . $c->sqliteTempStore());
            $this->db->exec('PRAGMA busy_timeout=' . $c->sqliteBusyTimeout());
            $this->db->exec('PRAGMA mmap_size=' . $c->sqliteMmapSize());

            $this->fts5Available = $this->probeFts5();

            $this->ensureMetaTable();

            // Only compute expensive index stats when a debug collector is actually
            // available — otherwise this COUNTs every table on every request.
            $collector = $this->resolveCollector();
            $indexedRecords = $collector !== null
                ? collect($this->getIndexStats())->sum('record_count')
                : null;

            $this->setCollectorEngineInfo([
                'version' => 'SQLite ' . $this->db->querySingle('SELECT sqlite_version()') . ' | FTS5',
                'tokenizer' => $this->illumiConfig->sqliteTokenizer(),
                'indexed_records' => $indexedRecords,
                'fts5_available' => $this->fts5Available,
            ]);
        }

        return $this->db;
    }

    public function __destruct()
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }

    protected function ensureMetaTable(): void
    {
        $this->db()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                model_class TEXT PRIMARY KEY,
                schema_version INTEGER NOT NULL DEFAULT 1,
                columns TEXT NOT NULL,
                last_synced_at TEXT
            )',
            $this->table(self::META_TABLE),
        ));
    }

    protected function ensureConfigTable(): void
    {
        $this->db()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
            $this->table(self::CONFIG_TABLE),
        ));
    }

    public function tableName(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $name = $this->table('idx_' . Str::lower(ltrim($name, '_')));

        return $name;
    }

    /**
     * @param  array<int|string, mixed>  $columns
     * @param  int[]  $prefixLengths
     */
    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $this->cachedTableExists = [];
        $this->db();
        $table = $this->tableName($modelClass);

        if (! $this->fts5Available) {
            throw new IllumiSearchException('FTS5 is not available');
        }

        // Ensure supporting tables exist for the current tenant scope
        $this->ensureMetaTable();
        $this->ensureConfigTable();

        $contentColumns = [];
        $columnDefinitions = [];

        foreach ($columns as $key => $config) {
            $colName = is_string($key) ? $key : $config;
            $safeName = $this->normalizeColumnName($colName);
            $contentColumns[] = $safeName;
            $columnDefinitions[] = $safeName;
        }

        $columnDefinitions[] = 'model_id';
        $columnDefinitions[] = 'last_synced_at UNINDEXED';

        $columnList = implode(', ', $columnDefinitions);

        $options = [];

        $tokenizerDef = $this->illumiConfig->sqliteTokenizer();
        $quote = str_contains($tokenizerDef, "'") ? '"' : "'";
        $options[] = "tokenize={$quote}{$tokenizerDef}{$quote}";

        if (! empty($prefixLengths)) {
            $options[] = "prefix='" . implode(' ', $prefixLengths) . "'";
        }

        $detail = $this->illumiConfig->sqliteDetail();
        if ($detail !== 'full') {
            $options[] = "detail={$detail}";
        }

        $columnsize = $this->illumiConfig->sqliteColumnsize();
        if ((int) $columnsize === 0) {
            $options[] = 'columnsize=0';
        }

        $optionString = implode(', ', $options);
        $sql = "CREATE VIRTUAL TABLE IF NOT EXISTS {$table} USING fts5({$columnList}, {$optionString})";

        $this->db()->exec($sql);

        // Set runtime FTS5 options (automerge, crisismerge, pgsz)
        $runtimeKeys = [
            'automerge' => $this->illumiConfig->sqliteAutomerge(),
            'crisismerge' => $this->illumiConfig->sqliteCrisismerge(),
            'pgsz' => $this->illumiConfig->sqlitePgsz(),
        ];

        foreach ($runtimeKeys as $key => $value) {
            try {
                $this->db()->exec("INSERT INTO {$table}({$table}) VALUES('{$key}={$value}')");
            } catch (\Exception) {
                // Silently skip invalid FTS5 runtime config keys
            }
        }

        // Vocab table for spellcheck
        $vocabTable = $table . '_vocab';
        $this->db()->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$vocabTable} USING fts5vocab({$table}, 'row')",
        );

        $this->updateMeta($modelClass, 1, $contentColumns);
    }

    public function dropTable(string $modelClass): void
    {
        $this->cachedTableExists = [];
        $table = $this->tableName($modelClass);
        $vocabTable = $table . '_vocab';
        $this->db()->exec("DROP TABLE IF EXISTS {$vocabTable}");
        $this->db()->exec("DROP TABLE IF EXISTS {$table}");

        // Meta table may not exist for tenant-scoped calls; create it if needed
        $this->ensureMetaTable();

        $stmt = $this->db()->prepare('DELETE FROM ' . $this->table(self::META_TABLE) . ' WHERE model_class = :model');
        $stmt->bindValue(':model', $modelClass, SQLITE3_TEXT);
        $stmt->execute();
    }

    /** @param array<string, string> $document */
    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $table = $this->tableName($modelClass);

        $document = $this->sanitizeDocumentKeys($document);
        $columns = array_keys($document);
        $placeholders = [];
        $values = [];

        $this->textProcessor ??= app(\Moaines\IllumiSearch\Contracts\TextProcessor::class);

        foreach ($columns as $col) {
            $raw = (string) ($document[$col] ?? '');
            $placeholders[] = ":{$col}";
            // Apply TextProcessor to all content (lowercase, diacritics, stopwords,
            // CJK separation) so the index matches what every other engine produces.
            $values[":{$col}"] = $this->textProcessor->process($raw, 'en');
        }

        $placeholders[] = ':last_synced_at';
        $values[':last_synced_at'] = date('Y-m-d H:i:s');

        $placeholders[] = ':model_id';
        $values[':model_id'] = (string) $modelId;

        // FTS5 tables have no UNIQUE constraint, so a plain INSERT OR REPLACE
        // would duplicate rows for the same model_id on every re-save. Use the
        // numeric model_id as the rowid so REPLACE overwrites; for string ids
        // (UUIDs) delete the previous row and use a deterministic rowid derived
        // from the id (no MAX(rowid)+1 race under concurrent writers).
        $numericId = ctype_digit((string) $modelId) ? (int) $modelId : null;

        if ($numericId === null) {
            $delete = $this->db()->prepare("DELETE FROM {$table} WHERE model_id = :id");
            $delete->bindValue(':id', (string) $modelId, SQLITE3_TEXT);
            $delete->execute();
        }

        $columnList = implode(', ', array_merge($columns, ['last_synced_at', 'model_id']));
        $placeholderList = implode(', ', $placeholders);

        $stmt = $this->db()->prepare(
            "INSERT OR REPLACE INTO {$table} (rowid, {$columnList}) VALUES (:rowid, {$placeholderList})",
        );

        $stmt->bindValue(':rowid', $numericId ?? $this->stringRowid($modelId), SQLITE3_INTEGER);
        foreach ($values as $param => $value) {
            $stmt->bindValue($param, $value, SQLITE3_TEXT);
        }

        $stmt->execute();

        if (! $this->isRebuilding) {
            $this->searchCache->clear();
        }
    }

    /**
     * Deterministic rowid for a string model id (UUID).
     * 60-bit sha256 — collision with numeric ids is practically impossible.
     */
    private function stringRowid(int|string $modelId): int
    {
        return (int) hexdec(substr(hash('sha256', (string) $modelId), 0, 15));
    }

    public function pruneExcessDocuments(string $modelClass): void
    {
        $max = $this->illumiConfig->maxDocumentsPerModel();
        if ($max <= 0) {
            return;
        }

        $table = $this->tableName($modelClass);

        if (! $this->tableExists($modelClass)) {
            return;
        }

        // Find the cutoff rowid (the N-th highest). rowid is the numeric model_id
        // when the id is numeric (see upsert), and insertion order otherwise —
        // both avoid the CAST(model_id AS INTEGER) that defeats the FTS5 index.
        $stmt = $this->db()->prepare(
            "SELECT rowid FROM {$table} ORDER BY rowid DESC LIMIT 1 OFFSET :offset"
        );
        $stmt->bindValue(':offset', $max - 1, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($result === false) {
            return;
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false || ! isset($row['rowid'])) {
            return; // Fewer than max documents
        }

        $cutoff = (int) $row['rowid'];
        $delStmt = $this->db()->prepare(
            "DELETE FROM {$table} WHERE rowid < :cutoff"
        );
        $delStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $delStmt->execute();
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $table = $this->tableName($modelClass);

        if (! $this->tableExists($modelClass)) {
            return;
        }

        $stmt = $this->db()->prepare("DELETE FROM {$table} WHERE model_id = :id");
        $stmt->bindValue(':id', (string) $modelId, SQLITE3_TEXT);
        $stmt->execute();

        $this->searchCache->clear();
    }

    /** @param array<int, array{model_id: int|string, document: array<string, string>}> $documents */
    public function insertBatch(string $modelClass, array $documents): void
    {
        $this->db()->exec('BEGIN TRANSACTION');

        try {
            foreach ($documents as $doc) {
                $this->upsert($modelClass, $doc['model_id'], $doc['document']);
            }
            $this->db()->exec('COMMIT');
        } catch (\Exception $e) {
            $this->db()->exec('ROLLBACK');
            throw $e;
        }

        if (! $this->isRebuilding) {
            $this->pruneExcessDocuments($modelClass);
            $this->searchCache->clear();
        }
    }

    /**
     * @param  array<class-string>  $modelClasses
     * @return Result[]
     */
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        if (empty(trim($query))) {
            return [];
        }

        // Two-layer cache: enriched > raw > search
        $cacheKey = $this->searchCache->key($query . $this->databasePath . ($this->tenantId() ?? '') . ($withSnippets ? '1' : '0'), $modelClasses, $limit, $offset, $mode);
        $enrichedKey = $this->searchCache->enrichedKey($cacheKey);
        $rawKey = $this->searchCache->rawKey($cacheKey);

        $cachedEnriched = $this->searchCache->get($enrichedKey);
        if ($cachedEnriched !== null) {
            return array_map(fn ($r) => Result::fromRaw($r), $cachedEnriched);
        }

        $cachedRaw = $this->searchCache->get($rawKey);
        if ($cachedRaw !== null) {
            $results = $cachedRaw;
            $searchDone = true;
        } else {
            $searchDone = false;
        }

        $safeQuery = $this->escapeQuery($query, $mode);
        $searchStart = microtime(true);

        if (! $searchDone) {
            $results = [];
            $seenIds = [];

        $this->resetDocCountCache();
        $docCount = $this->indexedDocCount($modelClasses);

        $perModel = ! empty($modelClasses) ? max(1, (int) ceil($limit / count($modelClasses))) : $limit;

        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $table = $this->tableName($modelClass);

            try {
                $sql = "SELECT *, -RANK AS rank, COUNT(*) OVER () as total_count FROM {$table} WHERE {$table} MATCH :query ORDER BY rank DESC LIMIT :limit OFFSET :offset";
                $stmt = $this->db()->prepare($sql);
                $stmt->bindValue(':query', $safeQuery, SQLITE3_TEXT);
                $stmt->bindValue(':limit', $perModel, SQLITE3_INTEGER);
                $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

                $result = $stmt->execute();

                if ($result === false) {
                    continue;
                }

                $modelResults = [];
                $pageTotalCount = null;

                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    if ($pageTotalCount === null && isset($row['total_count'])) {
                        $pageTotalCount = (int) $row['total_count'];
                    }

                    $modelId = ctype_digit($row['model_id']) ? (int) $row['model_id'] : $row['model_id'];
                    $uniqueId = "{$modelClass}:{$modelId}";

                    if (isset($seenIds[$uniqueId])) {
                        continue;
                    }
                    $seenIds[$uniqueId] = true;

                    $titleColumn = $this->getTitleColumn($row);

                    $modelResults[] = [
                        'modelClass' => $modelClass,
                        'modelId' => $modelId,
                        'rank' => $this->normalizeScore($row['rank'] ?? 0.0, $docCount, 1),
                        'title' => $row[$titleColumn] ?? $modelId,
                        'row' => $row,
                        'totalCount' => $pageTotalCount,
                    ];
                }

                array_push($results, ...$modelResults);
            } catch (\Exception $e) {
                Log::warning("illumi-search: FTS5 search failed for {$modelClass}: " . $e->getMessage(), [
                    'query' => $safeQuery ?? '',
                    'modelClass' => $modelClass,
                ]);

                continue;
            }
        }

        // Sort by rank across all model classes
            $results = collect($results)->sortByDesc('rank')->values()->all();

            $results = array_slice($results, 0, $limit);

            // NEAR post-filter: apply distance check before caching raw results
            $results = $this->nearFilterResults($results, $safeQuery);

            $this->searchCache->set($rawKey, $results);
        }

        // Enrich with snippets from original models
        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $query);
            $this->searchCache->set($enrichedKey, $results);
        }

        $tableNames = implode(', ', array_map(fn ($c) => $this->tableName($c), $modelClasses));
        $topScores = array_slice(array_column($results, 'rank'), 0, 3);
        $this->recordSearchQuery(
            matchQuery: $safeQuery,
            table: $tableNames,
            modelClass: implode(', ', $modelClasses),
            mode: $mode,
            resultCount: count($results),
            durationMs: round((microtime(true) - $searchStart) * 1000, 2),
            topScores: $topScores,
        );

        return array_map(
            fn ($r) => Result::fromRaw($r),
            $results,
        );
    }

    /**
     * @param  array<class-string>  $modelClasses
     */
    public function count(string $query, array $modelClasses): int
    {
        if (empty(trim($query))) {
            return 0;
        }

        $safeQuery = $this->escapeQuery($query, 'advanced');
        $total = 0;

        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $table = $this->tableName($modelClass);

            try {
                $stmt = $this->db()->prepare(
                    "SELECT COUNT(*) as cnt FROM {$table} WHERE {$table} MATCH :query",
                );
                $stmt->bindValue(':query', $safeQuery, SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                $total += (int) ($row['cnt'] ?? 0);
            } catch (\Exception) {
                // Skip if query fails for this table
            }
        }

        return $total;
    }

    private array $cachedTableExists = [];

    /** Identity of this engine's index scope (db path + tenant). */
    protected function docCountScopeKey(): string
    {
        return $this->databasePath . '|' . ($this->tenantId() ?? '');
    }

    /**
     * @param  array<class-string>  $modelClasses
     */
    protected function countDocsInScope(array $modelClasses): int
    {
        $count = 0;
        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }
            $table = $this->tableName($modelClass);
            $count += (int) $this->db()->querySingle("SELECT COUNT(*) FROM {$table}");
        }

        return $count;
    }

    public function clearTableCache(): void
    {
        $this->cachedTableExists = [];
    }

    public function tableExists(string $modelClass): bool
    {
        if (isset($this->cachedTableExists[$modelClass])) {
            return $this->cachedTableExists[$modelClass];
        }

        $table = $this->tableName($modelClass);
        $stmt = $this->db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $stmt->execute();

        $exists = $result !== false && $result->fetchArray(SQLITE3_NUM) !== false;
        $this->cachedTableExists[$modelClass] = $exists;

        return $exists;
    }

    public function integrityCheck(string $modelClass): bool
    {
        try {
            $table = $this->tableName($modelClass);

            if (! $this->tableExists($modelClass)) {
                return false;
            }

            $this->db()->exec("INSERT INTO {$table}({$table}) VALUES('integrity-check')");

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /** @return array<string> */
    public function listIndexTables(): array
    {
        $idxPrefix = $this->table('idx_');
        $metaTable = $this->table(self::META_TABLE);
        $configTable = $this->table(self::CONFIG_TABLE);

        $stmt = $this->db()->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ? AND name != ? AND name != ? AND name NOT LIKE ?",
        );
        $stmt->bindValue(1, $idxPrefix . '%', SQLITE3_TEXT);
        $stmt->bindValue(2, $metaTable, SQLITE3_TEXT);
        $stmt->bindValue(3, $configTable, SQLITE3_TEXT);
        $stmt->bindValue(4, $idxPrefix . '%_vocab', SQLITE3_TEXT);

        $result = $stmt->execute();

        $tables = [];
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public function dropIndexTable(string $modelClass): void
    {
        $prefix = $this->table('');
        $table = Str::startsWith($modelClass, $prefix) ? $modelClass : $this->tableName($modelClass);
        $this->db()->exec('DROP TABLE IF EXISTS ' . $table);
        $this->db()->exec('DROP TABLE IF EXISTS ' . $table . '_vocab');
    }

    /** @return array<class-string> */
    public function getIndexedModelClasses(): array
    {
        $result = $this->db()->query('SELECT model_class FROM ' . $this->table(self::META_TABLE));
        $classes = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $classes[] = $row['model_class'];
        }

        return $classes;
    }

    /** @return array<int, array{model_class: string, record_count: int, last_synced_at: ?string, columns: ?string}> */
    public function getIndexStats(): array
    {
        $models = $this->getIndexedModelClasses();
        $stats = [];

        foreach ($models as $modelClass) {
            $table = $this->tableName($modelClass);

            try {
                $result = $this->db()->query("SELECT COUNT(*) as cnt FROM {$table}");
            } catch (\Exception) {
                $result = false;
            }

            if ($result === false) {
                $this->cleanupOrphanedMeta($modelClass);

                continue;
            }

            $row = $result->fetchArray(SQLITE3_ASSOC);

            $metaResult = $this->db()->query(
                'SELECT last_synced_at, columns FROM ' . $this->table(self::META_TABLE) . " WHERE model_class = '" . SQLite3::escapeString($modelClass) . "'",
            );
            $meta = $metaResult ? $metaResult->fetchArray(SQLITE3_ASSOC) : false;

            $stats[] = [
                'model_class' => $modelClass,
                'record_count' => (int) ($row['cnt'] ?? 0),
                'last_synced_at' => $meta['last_synced_at'] ?? null,
                'columns' => $meta['columns'] ?? null,
            ];
        }

        return $stats;
    }

    public function vacuum(): void
    {
        $this->db()->exec('VACUUM');
    }

    /** @return array{vacuum: array{before: int, after: int}, tables_optimized: int} */
    public function optimize(): array
    {

        $results = [];

        // 1. VACUUM the database
        $beforeSize = $this->getDatabaseSize();
        $this->vacuum();
        $afterSize = $this->getDatabaseSize();
        $results['vacuum'] = ['before' => $beforeSize, 'after' => $afterSize];

        // 2. FTS5 merge optimization on each table
        $tables = $this->getIndexedModelClasses();
        $optimizedCount = 0;

        foreach ($tables as $modelClass) {
            $table = $this->tableName($modelClass);
            try {
                $this->db()->exec("INSERT INTO {$table}({$table}) VALUES('optimize')");
                $optimizedCount++;
            } catch (\Exception) {
                // Skip if table doesn't support optimize
            }
        }
        $results['tables_optimized'] = $optimizedCount;

        return $results;
    }

    /** @return list<string> */
    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        return $this->runSuggest($query, $maxDistance, $limit);
    }

    /**
     * Backend step: SQLite has no trigram index — candidate rows come from
     * the ASCII-prefix phase only (fts5vocab exposes no trigram table).
     *
     * @return iterable<array{word: string, ascii_word: string}>
     */
    protected function trigramCandidateRows(string $queryAscii, array $queryTrigrams, int $limit): iterable
    {
        return [];
    }

    /**
     * Backend step: fts5vocab rows whose term starts with the raw query prefix.
     * fts5vocab stores raw terms (no ascii_word) — we match on the RAW prefix
     * (mb_substr of the original query, not the ASCII form) so non-Latin
     * queries still find their script, then the shared ranker transliterates
     * each row's ascii on the fly.
     *
     * @return iterable<object{word: string, ascii_word: string}>
     */
    protected function prefixCandidateRows(string $query, string $queryAscii, string $prefix, int $limit): iterable
    {
        $rawPrefix = mb_substr(trim($query), 0, 2);
        if ($rawPrefix === '') {
            return [];
        }

        $rows = [];

        foreach ($this->getIndexedModelClasses() as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $vocabTable = $this->tableName($modelClass) . '_vocab';
            $vocabLimit = $this->illumiConfig->sqliteVocabLimit();

            try {
                $stmt = $this->db()->prepare(
                    "SELECT term, cnt FROM {$vocabTable} WHERE term IS NOT NULL AND term LIKE :prefix ORDER BY cnt DESC LIMIT {$vocabLimit}",
                );
                $stmt->bindValue(':prefix', $rawPrefix . '%', SQLITE3_TEXT);
            } catch (\Exception) {
                continue;
            }

            if ($stmt === false) {
                continue;
            }

            $result = $stmt->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = (object) ['word' => $row['term'], 'ascii_word' => ''];
            }
        }

        return $rows;
    }

    public function getSupportedOperators(): array
    {
        $this->ensureOperatorsProbed();
        $this->applyOperatorConfig();

        return $this->supportedOperators;
    }

    public function supportsPhraseSearch(): bool
    {
        return true;
    }

    public function supportsPrefixWildcard(): bool
    {
        return true;
    }

    /** @param string[] $columns */
    protected function updateMeta(string $modelClass, int $version, array $columns, ?string $syncedAt = null): void
    {
        $stmt = $this->db()->prepare(sprintf(
            'INSERT OR REPLACE INTO %s (model_class, schema_version, columns, last_synced_at) VALUES (:model, :version, :columns, :synced)',
            $this->table(self::META_TABLE),
        ));

        $stmt->bindValue(':model', $modelClass, SQLITE3_TEXT);
        $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
        $stmt->bindValue(':columns', json_encode($columns), SQLITE3_TEXT);
        $stmt->bindValue(':synced', $syncedAt ?? now()->toDateTimeString(), SQLITE3_TEXT);
        $stmt->execute();
    }

    protected function escapeQuery(string $query, string $mode): string
    {
        $cacheKey = md5($query . $mode);
        if (isset($this->cachedSafeQueries[$cacheKey])) {
            return $this->cachedSafeQueries[$cacheKey];
        }

        // Normalize query: lowercase + remove diacritics to match indexed content
        $query = $this->normalizeQuery($query);

        // Evict oldest entry if cache is full
        if (count($this->cachedSafeQueries) >= $this->maxCachedQueries) {
            array_shift($this->cachedSafeQueries);
        }

        return $this->cachedSafeQueries[$cacheKey] = match ($mode) {
            'basic' => $this->escapeBasicQuery($query),
            'raw' => $query,
            default => $this->escapeAdvancedQuery($query),
        };
    }

    private function escapeBasicQuery(string $query): string
    {
        $terms = [];

        foreach (OperatorRegistry::tokenize($query) as $token) {
            if (preg_match('/^"([^"]+)"$/', $token, $m)) {
                $terms[] = '"' . $m[1] . '"';
            } else {
                $clean = preg_replace('/[^\p{L}\p{N}\*-]/u', '', $token);
                if ($clean !== '') {
                    $terms[] = rtrim($clean, '*') . '*';
                }
            }
        }

        return implode(' ', $terms);
    }

    private function escapeAdvancedQuery(string $query): string
    {
        $terms = OperatorRegistry::tokenize($query);
        $escaped = [];
        $this->ensureOperatorsProbed();
        $this->applyOperatorConfig();
        $operatorsConfig = $this->illumiConfig->operators();

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            $termUpper = Str::upper($term);
            $baseOp = preg_replace('/\/\d+$/', '', $termUpper);

            if (in_array($baseOp, $this->supportedOperators, true)) {
                $escaped[] = $baseOp;

                continue;
            }

            // NEAR is handled by OperatorProcessor PHP filter — engine fallback to AND
            $nearOp = in_array($baseOp, ['NEAR'], true) && $operatorsConfig === null;

            if ($nearOp) {
                $escaped[] = 'AND';

                continue;
            }

            // Unsupported operator keyword → literal quoted term
            if (in_array($baseOp, ['AND', 'OR', 'NOT', 'NEAR'], true)) {
                $escaped[] = '"' . $term . '"';

                continue;
            }

            if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
                $escaped[] = $term;

                continue;
            }

            if (preg_match('/^[\p{L}_]+:.*$/u', $term)) {
                $escaped[] = $term;

                continue;
            }

            if (preg_match('/[:\-\(\)\^\+#\/\.]/', $term)) {
                $escaped[] = '"' . $term . '"';
            } else {
                $escaped[] = rtrim($term, '*') . '*';
            }
        }

        return implode(' ', $escaped);
    }

    protected function ensureOperatorsProbed(): void
    {
        if ($this->operatorsProbed) {
            return;
        }
        $this->operatorsProbed = true;

        if (! $this->fts5Available) {
            return;
        }

        try {
            $db = new SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE _fts_probe USING fts5(content)');
            $db->exec("INSERT INTO _fts_probe VALUES('test aaa bbb')");

            try {
                $result = @$db->query("SELECT rowid FROM _fts_probe WHERE _fts_probe MATCH 'aaa NEAR/10 bbb'");
                if ($result !== false && $result->fetchArray()) {
                    $this->supportedOperators[] = 'NEAR';
                }
            } catch (\Exception) {
                // operator not supported — skip
            }

            $db->close();
        } catch (\Exception) {
            // Can't probe — fallback to basics
        }

        // Save raw list before config filtering (for illumi-search:doctor)
        $this->rawSupportedOperators = $this->supportedOperators;
    }

    /**
     * Apply config restrictions to the probed operators.
     * Called separately from the probe so it can re-apply when config changes.
     */
    protected function applyOperatorConfig(): void
    {
        $allowed = $this->illumiConfig->operators();

        // Reset to raw probed list before applying config
        $this->supportedOperators = $this->rawSupportedOperators;

        if ($allowed === null) {
            return;
        }

        if (is_string($allowed)) {
            $allowed = array_map('trim', explode(',', $allowed));
        }

        if (is_array($allowed) && ! empty($allowed)) {
            $this->supportedOperators = array_intersect(
                $this->supportedOperators,
                $allowed,
            );
        } elseif (is_array($allowed) && empty($allowed)) {
            $this->supportedOperators = [];
        }
    }

    /** @return array<string, bool> operator → supported or not */
    public function getOperatorsWithSupportStatus(): array
    {
        $all = ['AND', 'OR', 'NOT', 'NEAR'];
        $result = [];

        foreach ($all as $op) {
            $result[$op] = in_array($op, $this->supportedOperators, true);
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    protected function getTitleColumn(array $row): string
    {
        $priority = ['title', 'name', 'label', 'titre', 'nom'];

        foreach ($priority as $col) {
            if (isset($row[$col]) && ! empty($row[$col])) {
                return $col;
            }
        }

        // Return first non-model_id, non-rank column
        foreach ($row as $col => $value) {
            if ($col !== 'model_id' && $col !== 'rank' && ! empty($value)) {
                return $col;
            }
        }

        return 'model_id';
    }

    private function cleanupOrphanedMeta(string $modelClass): void
    {
        try {
            $stmt = $this->db()->prepare('DELETE FROM ' . $this->table(self::META_TABLE) . ' WHERE model_class = :model');
            $stmt->bindValue(':model', $modelClass, \SQLITE3_TEXT);
            $stmt->execute();
        } catch (\Exception) {
            // Best-effort cleanup
        }
    }

    public function getEngineVersion(): string
    {
        $sqlite = $this->db()->querySingle('SELECT sqlite_version()');

        if (! $this->fts5Available) {
            return 'SQLite ' . $sqlite . ' (FTS5 unavailable)';
        }

        return 'SQLite ' . $sqlite . ' | FTS5';
    }

    public function isFts5Available(): bool
    {
        if ($this->db !== null) {
            return $this->fts5Available;
        }

        return $this->probeFts5();
    }

    private function table(string $name): string
    {
        $prefix = $this->illumiConfig->tablePrefix();
        $tenantId = $this->tenantId();

        $prefixed = $prefix . ltrim($name, '_');

        return $tenantId !== null ? "{$tenantId}_{$prefixed}" : $prefixed;
    }

    private function probeFts5(): bool
    {
        try {
            $db = new SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE _fts_probe USING fts5(content)');
            $db->close();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getPragma(string $name): string|int|null
    {
        $safe = ['journal_mode', 'synchronous', 'cache_size', 'temp_store',
            'busy_timeout', 'mmap_size', 'wal_autocheckpoint',
            'page_size', 'page_count', 'freelist_count',
            'application_id', 'user_version',
        ];

        if (! in_array($name, $safe, true)) {
            throw new IllumiSearchException("Unsupported or unsafe PRAGMA: {$name}");
        }

        return $this->db()->querySingle("PRAGMA {$name}");
    }

    /** @return array{passed: bool, errors: string[]} */
    public function fullIntegrityCheck(): array
    {
        $errors = [];
        $tables = $this->listIndexTables();

        if (empty($tables)) {
            return ['passed' => false, 'errors' => ['No FTS5 tables found']];
        }

        $shadowSuffixes = ['_data', '_idx', '_content', '_docsize', '_config', '_vocab'];

        foreach ($tables as $table) {
            $isShadow = false;
            foreach ($shadowSuffixes as $suffix) {
                if (str_ends_with($table, $suffix)) {
                    $isShadow = true;
                    break;
                }
            }

            if ($isShadow) {
                continue;
            }

            try {
                $this->db()->exec("INSERT INTO {$table}({$table}) VALUES('integrity-check')");
            } catch (\Exception $e) {
                $errors[] = $table . ': ' . $e->getMessage();
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }

    public function getEngineStatus(): array
    {
        return [
            'driver' => 'SQLite FTS5',
            'engine_version' => $this->getEngineVersion(),
            'database_path' => $this->getDatabasePath(),
            'database_size' => $this->getDatabaseSize(),
            'tokenizer' => $this->illumiConfig->sqliteTokenizer(),
            'detail' => $this->illumiConfig->sqliteDetail(),
            'columnsize' => $this->illumiConfig->sqliteColumnsize() ? 'Enabled' : 'Disabled',
            'prefix_lengths' => '[' . implode(', ', $this->illumiConfig->sqlitePrefixLengths()) . ']',
            'automerge' => $this->illumiConfig->sqliteAutomerge(),
            'crisismerge' => $this->illumiConfig->sqliteCrisismerge(),
            'wal' => $this->illumiConfig->sqliteWal() ? 'Enabled' : 'Disabled',
            'cache_size' => abs($this->illumiConfig->sqliteCacheSizeKb()) . ' KB',
            'busy_timeout' => $this->illumiConfig->sqliteBusyTimeout() . ' ms',
        ];
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $this->ensureConfigTable();

        $stmt = $this->db()->prepare(
            'SELECT value FROM ' . $this->table(self::CONFIG_TABLE) . ' WHERE key = :key',
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(\SQLITE3_ASSOC);

        return $row !== false ? ConfigHelper::decode($row['value'], $default) : $default;
    }

    public function setConfig(string $key, mixed $value): void
    {
        $this->ensureConfigTable();

        $stmt = $this->db()->prepare(
            'INSERT OR REPLACE INTO ' . $this->table(self::CONFIG_TABLE) . ' (key, value) VALUES (:key, :value)',
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':value', ConfigHelper::encode($value), \SQLITE3_TEXT);
        $stmt->execute();
    }
}

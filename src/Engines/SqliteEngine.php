<?php

namespace Moaines\IllumiSearch\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Debug\IllumiSearchCollector;
use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\ConfigHelper;
use Moaines\IllumiSearch\Support\OperatorRegistry;
use Moaines\IllumiSearch\Support\SnippetService;
use SQLite3;

class SqliteEngine implements Engine
{
    private const META_TABLE = '_search_meta';

    private const CONFIG_TABLE = '_search_config';

    private ?SQLite3 $db = null;

    private ?TextProcessor $textProcessor = null;

    /** @var list<string> */
    protected static array $supportedOperators = ['AND', 'OR', 'NOT'];

    /** @var list<string> */
    protected static array $rawSupportedOperators = ['AND', 'OR', 'NOT'];

    protected static bool $operatorsProbed = false;

    /** @var array<string, string> */
    private array $cachedSafeQueries = [];

    private int $maxCachedQueries = 1000;

    private bool $fts5Available = false;

    private ?IllumiSearchCollector $debugCollector = null;

    public static function resetOperators(): void
    {
        static::$operatorsProbed = false;
        static::$supportedOperators = ['AND', 'OR', 'NOT'];
        static::$rawSupportedOperators = ['AND', 'OR', 'NOT'];
    }

    public function __construct(
        private readonly string $databasePath,
        private readonly ?SnippetService $snippets = null,
    ) {}

    public function setTextProcessor(TextProcessor $processor): void
    {
        $this->textProcessor = $processor;
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    /**
     * @param array<string, string> $document
     * @return array<string, string>
     */
    protected function sanitizeDocumentKeys(array $document): array
    {
        $sanitized = [];
        foreach ($document as $key => $value) {
            $sanitized[str_replace(['.', '->', '-'], '_', $key)] = $value;
        }

        return $sanitized;
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

            $this->db->exec('PRAGMA busy_timeout='.config('illumi-search.fts5.busy_timeout', 15000));

            if (filter_var(config('illumi-search.fts5.wal', true), FILTER_VALIDATE_BOOLEAN)) {
                $this->db->exec('PRAGMA journal_mode=WAL');
            }
            $this->db->exec('PRAGMA synchronous='.config('illumi-search.fts5.synchronous', 'NORMAL'));
            $this->db->exec('PRAGMA cache_size='.config('illumi-search.fts5.cache_size_kb', -64000));
            $this->db->exec('PRAGMA temp_store='.config('illumi-search.fts5.temp_store', 'MEMORY'));
            $this->db->exec('PRAGMA busy_timeout='.config('illumi-search.fts5.busy_timeout', 15000));
            $this->db->exec('PRAGMA mmap_size='.config('illumi-search.fts5.mmap_size', 0));

            $this->fts5Available = $this->probeFts5();

            $this->ensureMetaTable();

            if ($collector = $this->resolveDebugCollector()) {
                $collector->setEngineInfo([
                    'version' => 'SQLite '.$this->db->querySingle('SELECT sqlite_version()').' | FTS5',
                    'tokenizer' => config('illumi-search.fts5.tokenizer', 'unicode61'),
                    'indexed_records' => collect($this->getIndexStats())->sum('record_count'),
                    'fts5_available' => $this->fts5Available,
                ]);
            }
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
            self::META_TABLE
        ));
    }

    protected function ensureConfigTable(): void
    {
        $this->db()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
            self::CONFIG_TABLE
        ));
    }

    public function tableName(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $name = 'idx_'.strtolower(ltrim($name, '_'));

        return $name;
    }

    /**
     * @param array<int|string, mixed> $columns
     * @param int[] $prefixLengths
     */
    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $this->db();

        if (! $this->fts5Available) {
            throw new IllumiSearchException(
                'FTS5 is not available in your SQLite build. '
                .'Install or compile SQLite with FTS5 enabled '
                .'(--enable-fts5 or SQLITE_ENABLE_FTS5). '
                .'Run "php artisan illumi-search:doctor" for details.'
            );
        }

        $table = $this->tableName($modelClass);

        $contentColumns = [];
        $columnDefinitions = [];

        foreach ($columns as $key => $config) {
            $colName = is_string($key) ? $key : $config;
            $safeName = str_replace(['.', '->', '-'], '_', $colName);
            $contentColumns[] = $safeName;
            $columnDefinitions[] = $safeName;
        }

        $columnDefinitions[] = 'model_id';

        $columnList = implode(', ', $columnDefinitions);

        $options = [];

        $tokenizerDef = config('illumi-search.fts5.tokenizer', 'unicode61');
        $options[] = "tokenize='{$tokenizerDef}'";

        if (! empty($prefixLengths)) {
            $options[] = "prefix='".implode(' ', $prefixLengths)."'";
        }

        $detail = config('illumi-search.fts5.detail', 'full');
        if ($detail !== 'full') {
            $options[] = "detail={$detail}";
        }

        $columnsize = config('illumi-search.fts5.columnsize', 1);
        if ((int) $columnsize === 0) {
            $options[] = 'columnsize=0';
        }

        $optionString = implode(', ', $options);
        $sql = "CREATE VIRTUAL TABLE IF NOT EXISTS {$table} USING fts5({$columnList}, {$optionString})";

        $this->db()->exec($sql);

        // Set runtime FTS5 options (automerge, crisismerge, pgsz)
        $runtimeKeys = [
            'automerge' => config('illumi-search.fts5.automerge', 4),
            'crisismerge' => config('illumi-search.fts5.crisismerge', 16),
            'pgsz' => config('illumi-search.fts5.pgsz', 1000),
        ];

        foreach ($runtimeKeys as $key => $value) {
            try {
                $this->db()->exec("INSERT INTO {$table}({$table}) VALUES('{$key}={$value}')");
            } catch (\Exception) {
                // Silently skip invalid FTS5 runtime config keys
            }
        }

        // Vocab table for spellcheck
        $vocabTable = $table.'_vocab';
        $this->db()->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$vocabTable} USING fts5vocab({$table}, 'row')"
        );

        $this->updateMeta($modelClass, 1, $contentColumns);
    }

    public function dropTable(string $modelClass): void
    {
        $table = $this->tableName($modelClass);
        $vocabTable = $table.'_vocab';
        $this->db()->exec("DROP TABLE IF EXISTS {$vocabTable}");
        $this->db()->exec("DROP TABLE IF EXISTS {$table}");

        $stmt = $this->db()->prepare('DELETE FROM '.self::META_TABLE.' WHERE model_class = :model');
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

        foreach ($columns as $col) {
            $placeholders[] = ":{$col}";
            $values[":{$col}"] = $document[$col];
        }

        $placeholders[] = ':model_id';
        $values[':model_id'] = (string) $modelId;

        $columnList = implode(', ', array_merge($columns, ['model_id']));
        $placeholderList = implode(', ', $placeholders);

        $stmt = $this->db()->prepare(
            "INSERT OR REPLACE INTO {$table} ({$columnList}) VALUES ({$placeholderList})"
        );

        foreach ($values as $param => $value) {
            $stmt->bindValue($param, $value, SQLITE3_TEXT);
        }

        $stmt->execute();
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

        $safeQuery = $this->escapeQuery($query, $mode);
        $results = [];
        $seenIds = [];

        $perModel = ! empty($modelClasses) ? max(1, (int) ceil($limit / count($modelClasses))) : $limit;

        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $table = $this->tableName($modelClass);
            $queryStart = microtime(true);

            try {
                $sql = "SELECT *, rank, COUNT(*) OVER () as total_count FROM {$table} WHERE {$table} MATCH :query ORDER BY rank DESC LIMIT :limit OFFSET :offset";
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
                        'rank' => $row['rank'] ?? 0.0,
                        'title' => $row[$titleColumn] ?? $modelId,
                        'row' => $row,
                        'totalCount' => $pageTotalCount,
                    ];
                }

                array_push($results, ...$modelResults);

                if ($collector = $this->resolveDebugCollector()) {
                    $collector->addQuery(
                        matchQuery: $safeQuery,
                        table: $table,
                        modelClass: $modelClass,
                        mode: $mode,
                        resultCount: count($modelResults),
                        durationMs: (microtime(true) - $queryStart) * 1000,
                        topScores: array_slice(array_column($modelResults, 'rank'), 0, 3),
                    );
                }
            } catch (\Exception $e) {
                report($e);

                continue;
            }
        }

        // Sort by rank across all model classes
        $results = collect($results)->sortByDesc('rank')->values()->all();

        $results = array_slice($results, 0, $limit);

        // Enrich with snippets from original models
        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $query);
        }

        return array_map(
            fn ($r) => Result::make(
                modelClass: $r['modelClass'],
                modelId: $r['modelId'],
                rank: $r['rank'],
                title: $r['title'],
                summary: $r['summary'] ?? null,
                raw: $r['row'],
                model: $r['eloquentModel'] ?? null,
                totalCount: $r['totalCount'] ?? null,
            ),
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
                    "SELECT COUNT(*) as cnt FROM {$table} WHERE {$table} MATCH :query"
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

    public function tableExists(string $modelClass): bool
    {
        $table = $this->tableName($modelClass);
        $result = $this->db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");

        return $result !== false && $result->fetchArray(SQLITE3_NUM) !== false;
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
        $result = $this->db()->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'idx_%' AND name != '".self::META_TABLE."'"
        );

        $tables = [];
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public function dropIndexTable(string $tableName): void
    {
        $vocabTable = $tableName.'_vocab';
        $this->db()->exec("DROP TABLE IF EXISTS {$vocabTable}");
        $this->db()->exec("DROP TABLE IF EXISTS {$tableName}");
    }

    /** @return array<class-string> */
    public function getIndexedModelClasses(): array
    {
        $result = $this->db()->query('SELECT model_class FROM '.self::META_TABLE);
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
                'SELECT last_synced_at, columns FROM '.self::META_TABLE." WHERE model_class = '".SQLite3::escapeString($modelClass)."'"
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
    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        if (! $this->tableExists($modelClass)) {
            return [];
        }

        $table = $this->tableName($modelClass);
        $vocabTable = $table.'_vocab';
        $suggestions = [];

        try {
            $vocabLimit = config('illumi-search.spellcheck.vocab_limit', 1000);
            $prefix = mb_substr($term, 0, 2);
            $stmt = $this->db()->prepare(
                "SELECT term, cnt FROM {$vocabTable} WHERE term IS NOT NULL AND term LIKE :prefix ORDER BY cnt DESC LIMIT {$vocabLimit}"
            );
            $stmt->bindValue(':prefix', $prefix.'%', SQLITE3_TEXT);

            if ($stmt === false) {
                return [];
            }

            $result = $stmt->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $vocabTerm = $row['term'];
                $distance = levenshtein($term, $vocabTerm);

                if ($distance > 0 && $distance <= $maxDistance) {
                    $suggestions[] = [
                        'term' => $vocabTerm,
                        'distance' => $distance,
                        'frequency' => (int) ($row['cnt'] ?? 0),
                    ];
                }
            }
        } catch (\Exception $e) {
            report($e);

            return [];
        }

        usort($suggestions, function ($a, $b) {
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] <=> $b['distance'];
            }

            return $b['frequency'] <=> $a['frequency'];
        });

        return array_column($suggestions, 'term');
    }

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        if (strlen(trim($query)) < 2) {
            return [];
        }

        $candidates = [];

        foreach ($this->getIndexedModelClasses() as $modelClass) {
            $results = $this->queryVocab($modelClass, $query, $maxDistance, $limit);
            $candidates = array_merge($candidates, $results);
        }

        return array_values(array_unique($candidates));
    }

    public function getSupportedOperators(): array
    {
        $this->ensureOperatorsProbed();
        $this->applyOperatorConfig();

        return static::$supportedOperators;
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
    protected function updateMeta(string $modelClass, int $version, array $columns): void
    {
        $stmt = $this->db()->prepare(sprintf(
            'INSERT OR REPLACE INTO %s (model_class, schema_version, columns, last_synced_at) VALUES (:model, :version, :columns, :synced)',
            self::META_TABLE
        ));

        $stmt->bindValue(':model', $modelClass, SQLITE3_TEXT);
        $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
        $stmt->bindValue(':columns', json_encode($columns), SQLITE3_TEXT);
        $stmt->bindValue(':synced', now()->toDateTimeString(), SQLITE3_TEXT);
        $stmt->execute();
    }

    protected function escapeQuery(string $query, string $mode): string
    {
        $cacheKey = md5($query.$mode);
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
                $terms[] = '"'.$m[1].'"';
            } else {
                $clean = preg_replace('/[^\p{L}\p{N}\*-]/u', '', $token);
                if ($clean !== '') {
                    $terms[] = rtrim($clean, '*').'*';
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
        $operatorsConfig = config('illumi-search.operators.enabled');

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            $termUpper = strtoupper($term);
            $baseOp = preg_replace('/\/\d+$/', '', $termUpper);

            if (in_array($baseOp, static::$supportedOperators, true)) {
                $escaped[] = $baseOp;

                continue;
            }

            if ($baseOp === 'NEAR' && $operatorsConfig === null) {
                $escaped[] = 'AND';

                continue;
            }

            // Unsupported operator keyword → literal quoted term
            if (in_array($baseOp, ['AND', 'OR', 'NOT', 'NEAR'], true)) {
                $escaped[] = '"'.$term.'"';

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

            if (preg_match('/[:\-\(\)\^]/', $term)) {
                $escaped[] = '"'.$term.'"';
            } else {
                $escaped[] = rtrim($term, '*').'*';
            }
        }

        return implode(' ', $escaped);
    }

    protected function normalizeQuery(string $query): string
    {
        if ($this->textProcessor === null) {
            $this->textProcessor = app(TextProcessor::class);
        }

        return $this->textProcessor->process($query);
    }

    protected function ensureOperatorsProbed(): void
    {
        if (static::$operatorsProbed) {
            return;
        }
        static::$operatorsProbed = true;

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
                    static::$supportedOperators[] = 'NEAR';
                }
            } catch (\Exception) {
                // operator not supported — skip
            }

            $db->close();
        } catch (\Exception) {
            // Can't probe — fallback to basics
        }

        // Save raw list before config filtering (for illumi-search:doctor)
        static::$rawSupportedOperators = static::$supportedOperators;
    }

    /**
     * Apply config restrictions to the probed operators.
     * Called separately from the probe so it can re-apply when config changes.
     */
    protected function applyOperatorConfig(): void
    {
        $allowed = config('illumi-search.operators.enabled');

        // Reset to raw probed list before applying config
        static::$supportedOperators = static::$rawSupportedOperators;

        if ($allowed === null) {
            return;
        }

        if (is_string($allowed)) {
            $allowed = array_map('trim', explode(',', $allowed));
        }

        if (is_array($allowed) && ! empty($allowed)) {
            static::$supportedOperators = array_intersect(
                static::$supportedOperators,
                $allowed
            );
        } elseif (is_array($allowed) && empty($allowed)) {
            static::$supportedOperators = [];
        }
    }

    /** @return array<string, bool> operator → supported or not */
    public static function getOperatorsWithSupportStatus(): array
    {
        $all = ['AND', 'OR', 'NOT', 'NEAR'];
        $result = [];

        foreach ($all as $op) {
            $result[$op] = in_array($op, static::$supportedOperators, true);
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
            $stmt = $this->db()->prepare('DELETE FROM '.self::META_TABLE.' WHERE model_class = :model');
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
            return 'SQLite '.$sqlite.' (FTS5 unavailable)';
        }

        return 'SQLite '.$sqlite.' | FTS5';
    }

    public function isFts5Available(): bool
    {
        if ($this->db !== null) {
            return $this->fts5Available;
        }

        return $this->probeFts5();
    }

    private function probeFts5(): bool
    {
        try {
            $db = new \SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE _fts_probe USING fts5(content)');
            $db->close();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function resolveDebugCollector(): ?IllumiSearchCollector
    {
        if ($this->debugCollector !== null) {
            return $this->debugCollector;
        }

        if (! class_exists(\DebugBar\StandardDebugBar::class)) {
            return $this->debugCollector = null;
        }

        try {
            $debugbar = app('debugbar');

            if (! $debugbar?->hasCollector('illumi-search')) {
                $collector = new IllumiSearchCollector;
                $debugbar->addCollector($collector);
            }

            $this->debugCollector = $debugbar?->getCollector('illumi-search');

            return $this->debugCollector;
        } catch (\Exception) {
            return $this->debugCollector = null;
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
                $errors[] = $table.': '.$e->getMessage();
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
            'tokenizer' => config('illumi-search.engines.sqlite.fts5.tokenizer', 'unicode61'),
            'detail' => config('illumi-search.engines.sqlite.fts5.detail', 'full'),
            'columnsize' => config('illumi-search.engines.sqlite.fts5.columnsize', 1) ? 'Enabled' : 'Disabled',
            'prefix_lengths' => '[' . implode(', ', config('illumi-search.engines.sqlite.fts5.prefix_lengths', [2, 3, 4])) . ']',
            'automerge' => config('illumi-search.engines.sqlite.fts5.automerge', 4),
            'crisismerge' => config('illumi-search.engines.sqlite.fts5.crisismerge', 16),
            'wal' => config('illumi-search.engines.sqlite.runtime.wal', true) ? 'Enabled' : 'Disabled',
            'cache_size' => abs(config('illumi-search.engines.sqlite.runtime.cache_size_kb', -64000)) . ' KB',
            'busy_timeout' => config('illumi-search.engines.sqlite.runtime.busy_timeout', 15000) . ' ms',
        ];
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $this->ensureConfigTable();

        $stmt = $this->db()->prepare(
            'SELECT value FROM '.self::CONFIG_TABLE.' WHERE key = :key'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(\SQLITE3_ASSOC);

        return $row !== false ? ConfigHelper::decode($row['value'], $default) : $default;
    }

    public function setConfig(string $key, mixed $value): void
    {
        $this->ensureConfigTable();

        $stmt = $this->db()->prepare(
            'INSERT OR REPLACE INTO '.self::CONFIG_TABLE.' (key, value) VALUES (:key, :value)'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':value', ConfigHelper::encode($value), \SQLITE3_TEXT);
        $stmt->execute();
    }
}

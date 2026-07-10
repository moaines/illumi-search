<?php

namespace Moaines\LaravelFts\Engines;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Concerns\HasQueryTerms;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Exceptions\FtsException;
use Moaines\LaravelFts\FtsResult;
use SQLite3;

class SqliteFtsEngine implements FtsEngine
{
    use HasQueryTerms;
    private const META_TABLE = '_fts_meta';

    private ?SQLite3 $db = null;

    private ?TextProcessor $textProcessor = null;

    private static array $supportedOperators = ['AND', 'OR', 'NOT'];

    private static array $rawSupportedOperators = ['AND', 'OR', 'NOT'];

    private static bool $operatorsProbed = false;

    private array $cachedSafeQueries = [];

    public static function resetOperators(): void
    {
        static::$operatorsProbed = false;
        static::$supportedOperators = ['AND', 'OR', 'NOT'];
        static::$rawSupportedOperators = ['AND', 'OR', 'NOT'];
    }

    public function __construct(
        private readonly string $databasePath,
    ) {}

    public function setTextProcessor(TextProcessor $processor): void
    {
        $this->textProcessor = $processor;
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

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

            if (config('fts.fts5.wal', true)) {
                $this->db->exec('PRAGMA journal_mode=WAL');
            }
            $this->db->exec('PRAGMA synchronous=' . config('fts.fts5.synchronous', 'NORMAL'));
            $this->db->exec('PRAGMA cache_size=' . config('fts.fts5.cache_size_kb', -64000));
            $this->db->exec('PRAGMA temp_store=' . config('fts.fts5.temp_store', 'MEMORY'));

            $this->ensureMetaTable();
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

    public function tableName(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $name = 'idx_'.strtolower(ltrim($name, '_'));

        return $name;
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
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

        $tokenizerDef = config('fts.fts5.tokenizer', 'unicode61');
        $options[] = "tokenize='{$tokenizerDef}'";

        if (! empty($prefixLengths)) {
            $options[] = "prefix='" . implode(' ', $prefixLengths) . "'";
        }

        $detail = config('fts.fts5.detail', 'full');
        if ($detail !== 'full') {
            $options[] = "detail={$detail}";
        }

        $optionString = implode(', ', $options);
        $sql = "CREATE VIRTUAL TABLE IF NOT EXISTS {$table} USING fts5({$columnList}, {$optionString})";

        $this->db()->exec($sql);

        // Set runtime FTS5 options (automerge, crisismerge, pgsz)
        $runtimeKeys = [
            'automerge'   => config('fts.fts5.automerge', 4),
            'crisismerge' => config('fts.fts5.crisismerge', 16),
            'pgsz'        => config('fts.fts5.pgsz', 1000),
        ];

        foreach ($runtimeKeys as $key => $value) {
            @$this->db()->exec("INSERT INTO {$table}({$table}) VALUES('{$key}={$value}')");
        }

        // Vocab table for spellcheck
        $vocabTable = $table . '_vocab';
        $this->db()->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$vocabTable} USING fts5vocab({$table}, 'row')"
        );

        $this->updateMeta($modelClass, 1, $contentColumns);
    }

    public function dropTable(string $modelClass): void
    {
        $table = $this->tableName($modelClass);
        $vocabTable = $table . '_vocab';
        $this->db()->exec("DROP TABLE IF EXISTS {$vocabTable}");
        $this->db()->exec("DROP TABLE IF EXISTS {$table}");

        $stmt = $this->db()->prepare('DELETE FROM '.self::META_TABLE.' WHERE model_class = :model');
        $stmt->bindValue(':model', $modelClass, SQLITE3_TEXT);
        $stmt->execute();
    }

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

            try {
                $sql = "SELECT *, rank FROM {$table} WHERE {$table} MATCH :query ORDER BY rank LIMIT :limit OFFSET :offset";
                $stmt = $this->db()->prepare($sql);
                $stmt->bindValue(':query', $safeQuery, SQLITE3_TEXT);
                $stmt->bindValue(':limit', $perModel, SQLITE3_INTEGER);
                $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

                $result = @$stmt->execute();

                if ($result === false) {
                    continue;
                }

                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $modelId = $row['model_id'];
                    $uniqueId = "{$modelClass}:{$modelId}";

                    if (isset($seenIds[$uniqueId])) {
                        continue;
                    }
                    $seenIds[$uniqueId] = true;

                    $titleColumn = $this->getTitleColumn($row);

                    $results[] = [
                        'modelClass' => $modelClass,
                        'modelId' => $modelId,
                        'rank' => $row['rank'] ?? 0.0,
                        'title' => $row[$titleColumn] ?? $modelId,
                        'row' => $row,
                    ];
                }
            } catch (\Exception $e) {
                report($e);
                continue;
            }
        }

        // Sort by rank across all model classes
        usort($results, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        $results = array_slice($results, 0, $limit);

        // Enrich with snippets from original models
        $results = $withSnippets ? $this->enrichWithSnippets($results, $query) : $results;

        return array_map(
            fn ($r) => FtsResult::make(
                modelClass: $r['modelClass'],
                modelId: $r['modelId'],
                rank: $r['rank'],
                title: $r['title'],
                summary: $r['summary'] ?? null,
                raw: $r['row'],
                model: $r['eloquentModel'] ?? null,
            ),
            $results,
        );
    }

    protected function enrichWithSnippets(array $results, string $query): array
    {
        $grouped = [];
        $order = [];

        foreach ($results as $i => $r) {
            $grouped[$r['modelClass']][] = $r;
            $order[] = ['modelClass' => $r['modelClass'], 'index' => count($grouped[$r['modelClass']]) - 1];
        }

        $searchTerms = $this->extractSearchTerms($query);

        foreach ($grouped as $modelClass => &$entries) {
            $ids = array_column($entries, 'modelId');

            if (! class_exists($modelClass) || empty($ids)) {
                continue;
            }

            try {
                $instance = new $modelClass;
                $keyName = $instance->getKeyName();

                $snippetCols = $this->resolveSnippetColumns($instance);
                $defaultCols = ['body', 'content', 'description', 'text', 'excerpt'];
                $textColumns = $snippetCols ?? $defaultCols;

                // Build optimized select: only local DB columns needed for snippets
                $selectCols = [$keyName];
                $relationCols = [];

                foreach ($textColumns as $col) {
                    if (str_contains($col, '.')) {
                        $relName = explode('.', $col)[0];
                        if (! in_array($relName, $relationCols, true)) {
                            $relationCols[] = $relName;
                        }
                    } elseif (Schema::hasColumn($instance->getTable(), $col)) {
                        $selectCols[] = $col;
                    }
                    // Non-dot, non-DB → virtual accessor → no select needed
                }

                $query = $modelClass::whereIn($keyName, $ids);

                if (count($selectCols) > 1) {
                    $query->select($selectCols);
                }

                $models = $query->get()->keyBy->getKey();

                if (! empty($relationCols)) {
                    $models->load(array_unique($relationCols));
                }

                foreach ($entries as &$entry) {
                    $model = $models[$entry['modelId']] ?? null;
                    if ($model === null) {
                        continue;
                    }

                    $entry['eloquentModel'] = $model;
                    $entry['title'] = $model->title ?? $model->{$this->getTitleColumn($entry['row'])} ?? $entry['title'];
                    $entry['summary'] = $this->extractSnippet($model, $searchTerms, $snippetCols);
                }
            } catch (\Exception) {
                continue;
            }
        }

        $enriched = [];
        foreach ($results as $i => $r) {
            $mc = $r['modelClass'];
            $gi = $order[$i]['index'];
            $enriched[] = $grouped[$mc][$gi];
        }

        return $enriched;
    }

    /**
     * Determine which columns are allowed for snippet extraction.
     * Checks the model's $ftsSearchable for 'snippet' config.
     */
    protected function resolveSnippetColumns(Model $model): ?array
    {
        if (! method_exists($model, 'getFtsSearchableColumns')) {
            return null;
        }

        $raw = $model->getFtsSearchableColumns();
        if (empty($raw)) {
            return null;
        }

        if (! method_exists($model, 'normalizeFtsSearchable')) {
            return null;
        }

        $searchable = $model->normalizeFtsSearchable();
        $allowed = [];

        foreach ($searchable as $column => $config) {
            $snippetEnabled = $config['snippet'] ?? true;
            if ($snippetEnabled) {
                $allowed[] = $column;
            }
        }

        return ! empty($allowed) ? $allowed : null;
    }

    protected function extractSearchTerms(string $query): array
    {
        return $this->extractQueryTerms($query);
    }

    protected function extractSnippet(Model $model, array $searchTerms, ?array $snippetColumns = null): ?string
    {
        $defaultColumns = ['body', 'content', 'description', 'text', 'excerpt'];
        $textColumns = $snippetColumns ?? $defaultColumns;
        $sourceText = null;
        $bestPos = null;
        $bestTerm = '';

        foreach ($textColumns as $col) {
            $value = $this->snippetColumnValue($model, $col);

            if (! is_string($value) || strlen($value) <= 50) {
                continue;
            }

            $lower = mb_strtolower(strip_tags($value));

            foreach ($searchTerms as $term) {
                $termLower = mb_strtolower($term);
                $pos = mb_strpos($lower, $termLower);
                if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                    $bestPos = $pos;
                    $bestTerm = $term;
                    $sourceText = $value;
                }
            }
        }

        if ($sourceText === null) {
            foreach ($textColumns as $col) {
                $value = $this->snippetColumnValue($model, $col);
                if (is_string($value) && strlen($value) > 50) {
                    $sourceText = $value;
                    $bestPos = 0;
                    break;
                }
            }

            if ($sourceText === null) {
                return null;
            }
        }

        // Extract window around match
        $windowSize = 120;
        $snippetStart = max(0, $bestPos - 60);
        $snippetLen = min(mb_strlen($sourceText), $windowSize);
        $snippet = mb_substr(strip_tags($sourceText), $snippetStart, $snippetLen);

        // Add ellipsis if truncated
        if ($snippetStart > 0) {
            $snippet = '…' . $snippet;
        }
        if ($snippetStart + $snippetLen < mb_strlen(strip_tags($sourceText)) - 1) {
            $snippet .= '…';
        }

        // Highlight matching terms
        foreach ($searchTerms as $term) {
            if (empty(trim($term))) {
                continue;
            }
            $snippet = preg_replace(
                '/' . preg_quote($term, '/') . '/iu',
                '<mark>$0</mark>',
                $snippet,
            );
        }

        return $snippet;
    }

    private function snippetColumnValue(Model $model, string $col): string
    {
        if (str_contains($col, '.') && method_exists($model, 'resolveFtsValue')) {
            return $model->resolveFtsValue($col);
        }

        return $model->{$col} ?? '';
    }

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
        $vocabTable = $tableName . '_vocab';
        $this->db()->exec("DROP TABLE IF EXISTS {$vocabTable}");
        $this->db()->exec("DROP TABLE IF EXISTS {$tableName}");
    }

    public function getIndexedModelClasses(): array
    {
        $result = $this->db()->query('SELECT model_class FROM '.self::META_TABLE);
        $classes = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $classes[] = $row['model_class'];
        }

        return $classes;
    }

    public function getIndexStats(): array
    {
        $models = $this->getIndexedModelClasses();
        $stats = [];

        foreach ($models as $modelClass) {
            $table = $this->tableName($modelClass);

            $result = @$this->db()->query("SELECT COUNT(*) as cnt FROM {$table}");

            if ($result === false) {
                // Orphaned meta entry — clean up and skip
                $escaped = SQLite3::escapeString($modelClass);
                $this->db()->exec("DELETE FROM ".self::META_TABLE." WHERE model_class = '{$escaped}'");
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

    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        if (! $this->tableExists($modelClass)) {
            return [];
        }

        $table = $this->tableName($modelClass);
        $vocabTable = $table . '_vocab';
        $suggestions = [];

        try {
            $vocabLimit = config('fts.spellcheck.vocab_limit', 1000);
            $prefix = mb_substr($term, 0, 2);
            $stmt = $this->db()->prepare(
                "SELECT term, cnt FROM {$vocabTable} WHERE term IS NOT NULL AND term LIKE :prefix ORDER BY cnt DESC LIMIT {$vocabLimit}"
            );
            $stmt->bindValue(':prefix', $prefix . '%', SQLITE3_TEXT);

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

        return array_slice(array_map(fn ($s) => $s['term'], $suggestions), 0, $limit);
    }

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
        $cacheKey = md5($query . $mode);
        if (isset($this->cachedSafeQueries[$cacheKey])) {
            return $this->cachedSafeQueries[$cacheKey];
        }

        // Normalize query: lowercase + remove diacritics to match indexed content
        $query = $this->normalizeQuery($query);

        if ($mode === 'basic') {
            $escaped = preg_replace('/[^\p{L}\p{N}\s\-\*]/u', ' ', $query);
            $terms = array_filter(explode(' ', $escaped));
            $terms = array_map(fn ($t) => trim($t).'*', $terms);

            return $this->cachedSafeQueries[$cacheKey] = implode(' ', $terms);
        }

        if ($mode === 'raw') {
            // No wildcards, no escaping — bare normalized query
            return $this->cachedSafeQueries[$cacheKey] = $query;
        }

        // For advanced mode, split into terms and quote those with special chars
        $terms = preg_split('/\s+/', trim($query));
        $escaped = [];
        $this->ensureOperatorsProbed();
        $this->applyOperatorConfig();
        $operatorsConfig = config('fts.operators.enabled');

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            // Preserve FTS5 operators without wildcards
            $termUpper = strtoupper($term);
            $baseOp = preg_replace('/\/\d+$/', '', $termUpper);

            if (in_array($baseOp, static::$supportedOperators, true)) {
                $escaped[] = $baseOp; // uppercase — FTS5 requires uppercase operators
                continue;
            }

            // Fallback: unsupported NEAR → AND (only if config doesn't restrict operators)
            if ($baseOp === 'NEAR' && $operatorsConfig === null) {
                $escaped[] = 'AND';
                continue;
            }

            // Keep existing quoted phrases intact
            if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
                $escaped[] = $term;
                continue;
            }

            // Keep column:value syntax intact
            if (preg_match('/^[\p{L}_]+:.*$/u', $term)) {
                $escaped[] = $term;
                continue;
            }

            // Quote terms with special FTS5 characters (hyphens, parens, etc.)
            if (preg_match('/[:\-\(\)\^]/', $term)) {
                $escaped[] = '"' . $term . '"';
            } else {
                // Add prefix wildcard for search-as-you-type
                $escaped[] = $term . '*';
            }
        }

        return $this->cachedSafeQueries[$cacheKey] = implode(' ', $escaped);
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

        try {
            $db = new \SQLite3(':memory:');
            $db->exec("CREATE VIRTUAL TABLE _fts_probe USING fts5(content)");
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

        // Save raw list before config filtering (for fts:doctor)
        static::$rawSupportedOperators = static::$supportedOperators;
    }

    /**
     * Apply config restrictions to the probed operators.
     * Called separately from the probe so it can re-apply when config changes.
     */
    protected function applyOperatorConfig(): void
    {
        $allowed = config('fts.operators.enabled');

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

    public static function getSupportedOperators(): array
    {
        return static::$supportedOperators;
    }

    public static function getRawSupportedOperators(): array
    {
        return static::$rawSupportedOperators;
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
}

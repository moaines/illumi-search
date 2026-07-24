<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Stopwords\StopwordFilter;
use Moaines\IllumiSearch\Support\ConfigHelper;
use Moaines\IllumiSearch\Support\OperatorRegistry;
use Moaines\IllumiSearch\Support\SearchCache;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Text\HasScoring;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Text\NoopVacuum;
use Moaines\IllumiSearch\Text\NullPragma;
use Moaines\IllumiSearch\Text\StubQueryVocab;
use Symfony\Component\String\UnicodeString;

class MySqlEngine implements Engine
{
    use HasScoring;
    use HasTextHelpers;
    use NoopVacuum;
    use NullPragma;
    use StubQueryVocab;

    private const TABLE = 'index';

    private const CONFIG_TABLE = 'config';

    private const VOCAB_TABLE = 'vocab';

    private const TRIGRAM_TABLE = 'vocab_trigrams';

    private const SCRIPT_MISMATCH_PENALTY = 3;

    public const CONNECTION_NAME = 'illumi-search-mysql';

    private string $createdTableName = '';
    private string $connection = self::CONNECTION_NAME;
    private ?SnippetService $snippets = null;
    private bool $isRebuilding = false;
    private SearchCache $searchCache;

    /** @var array<string, bool> */
    private static array $checkedSearchable = [];

    public function __construct(?SnippetService $snippets = null)
    {
        $this->registerConnection();
        $this->snippets = $snippets;
        $this->searchCache = new SearchCache(storage_path('app/illumi-search-mysql'));
    }

    private function registerConnection(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $key = 'database.connections.' . self::CONNECTION_NAME;

        if (config()->has($key)) {
            return;
        }

        config([$key => [
            'driver' => 'mysql',
            'host' => config('illumi-search.engines.mysql.connection.host', '127.0.0.1'),
            'port' => config('illumi-search.engines.mysql.connection.port', '3306'),
            'database' => config('illumi-search.engines.mysql.connection.database', 'illumi_search'),
            'username' => config('illumi-search.engines.mysql.connection.username', 'root'),
            'password' => config('illumi-search.engines.mysql.connection.password', ''),
            'unix_socket' => config('illumi-search.engines.mysql.connection.unix_socket', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);
    }

    public function isFts5Available(): bool
    {
        // MySQL uses FULLTEXT indexes, not SQLite FTS5.
        return false;
    }

    public function getEngineVersion(): string
    {
        try {
            $row = DB::connection($this->connection)->selectOne('SELECT VERSION() AS v');
            $version = $row->v ?? '?';
        } catch (\Exception) {
            $version = '?';
        }

        return "MySQL {$version} | FULLTEXT (illumi-search)";
    }

    public function getDatabasePath(): string
    {
        return $this->connection;
    }

    public function getDatabaseSize(): ?int
    {
        $row = DB::connection($this->connection)->selectOne(
            'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()',
        );

        return $row->size !== null ? (int) $row->size : null;
    }

    public function vacuum(): void {}

    // ─── Schema ─────────────────────────────────────────

    /**
     * @return string[] Column names like text_w1, text_w2 (cached per request).
     */
    private function getExistingWeightColumns(bool $refresh = false): array
    {
        static $columns = null;

        if ($columns !== null && ! $refresh) {
            return $columns;
        }

        $result = DB::connection($this->connection)
            ->select("SHOW COLUMNS FROM " . $this->table(self::TABLE) . " WHERE Field LIKE 'text\\_w%'");

        $columns = collect($result)->map(fn ($c) => $c->Field)->sort()->values()->all();

        return $columns;
    }

    private function ensureWeightColumnsExist(int $maxWeight): void
    {
        $existing = $this->getExistingWeightColumns(true);

        for ($w = 1; $w <= $maxWeight; $w++) {
            $col = "text_w{$w}";
            if (in_array($col, $existing, true)) {
                continue;
            }

            DB::connection($this->connection)->statement(
                "ALTER TABLE " . $this->table(self::TABLE) . " ADD COLUMN {$col} LONGTEXT NOT NULL DEFAULT '' AFTER last_synced_at",
            );
            DB::connection($this->connection)->statement(
                "ALTER TABLE " . $this->table(self::TABLE) . " ADD FULLTEXT INDEX idx_fts_w{$w} ({$col})",
            );
        }

        // Refresh cache after adding columns
        $this->getExistingWeightColumns(true);
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $currentTable = $this->table(self::TABLE);

        // Tenant-aware guard: only DROP + CREATE when the table name changes
        // (e.g., switching tenants). For the same table, this is a no-op.
        if ($currentTable === $this->createdTableName) {
            return;
        }

        $maxWeight = (int) config('illumi-search.processing.max_weight', 3);

        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS ' . $currentTable);
        DB::connection($this->connection)->statement('
            CREATE TABLE ' . $currentTable . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_type VARCHAR(255) NOT NULL,
                model_id VARCHAR(255) NOT NULL,
                last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_model_model_id (model_type, model_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->ensureWeightColumnsExist($maxWeight);

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::CONFIG_TABLE) . ' (
                `key` VARCHAR(255) PRIMARY KEY,
                `value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::VOCAB_TABLE) . ' (
                word VARCHAR(255) NOT NULL UNIQUE,
                ascii_word VARCHAR(255) NOT NULL DEFAULT \'\',
                doc_count INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_vocab_ascii (ascii_word, doc_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::TRIGRAM_TABLE) . ' (
                trigram   CHAR(3) NOT NULL,
                word      VARCHAR(255) NOT NULL,
                doc_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (trigram, word),
                INDEX idx_word (word)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $this->createdTableName = $currentTable;
    }

    /**
     * Delete all indexed documents for a model class.
     * (Semantically equivalent to dropping an FTS5 virtual table in
     * SqliteEngine — all data for this model is removed from the shared
     * search_index table.)
     */
    public function dropTable(string $modelClass): void
    {
        if (! $this->tableExistsAny()) {
            return;
        }

        DB::connection($this->connection)->delete(
            'DELETE FROM ' . $this->table(self::TABLE) . ' WHERE model_type = ?',
            [$modelClass],
        );
    }

    public function dropIndexTable(string $modelClass): void
    {
        $this->dropTable($modelClass);
    }

    public function tableName(string $modelClass): string
    {
        return $this->table(self::TABLE);
    }

    public function tableExists(string $modelClass): bool
    {
        return $this->tableExistsAny();
    }

    /**
     * Check if the search_index table exists in MySQL.
     * Note: this is a global check (table exists at all), not per-model.
     */
    private function table(string $name): string
    {
        $prefix = config('illumi-search.processing.table_prefix', 'illumi_search_');
        $tenantId = app(TenantManager::class)->tenantId();

        $prefixed = $prefix . ltrim($name, '_');

        return $tenantId !== null ? "{$tenantId}_{$prefixed}" : $prefixed;
    }

    private function tableExistsAny(): bool
    {
        $row = DB::connection($this->connection)->selectOne('
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ', [$this->table(self::TABLE)]);

        return $row !== null;
    }

    public function listIndexTables(): array
    {
        return $this->tableExistsAny() ? [$this->table(self::TABLE)] : [];
    }

    public function getIndexStats(): array
    {
        $rows = DB::connection($this->connection)->select('
            SELECT model_type, COUNT(*) AS record_count, MAX(last_synced_at) AS last_synced_at
            FROM ' . $this->table(self::TABLE) . '
            GROUP BY model_type
        ');

        return array_map(fn ($r) => [
            'model_class' => $r->model_type,
            'record_count' => (int) $r->record_count,
            'last_synced_at' => $r->last_synced_at,
            'columns' => null,
        ], $rows);
    }

    public function getIndexedModelClasses(): array
    {
        return DB::connection($this->connection)->table($this->table(self::TABLE))
            ->select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->all();
    }

    private function concatWeightColumns(): string
    {
        return 'CONCAT(' . implode(", ' ', ", $this->getExistingWeightColumns()) . ')';
    }

    // ─── CRUD ───────────────────────────────────────────

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $weightCols = $this->buildSearchText($modelClass, $document);
        $newTextFlat = trim(implode(' ', $weightCols));
        $newWords = $this->tokenizeText($newTextFlat);
        $oldWords = [];

        if (! $this->isRebuilding) {
            $concatExpr = $this->concatWeightColumns();
            $oldResult = DB::connection($this->connection)
                ->table($this->table(self::TABLE))
                ->select(DB::raw("{$concatExpr} AS text_concat"))
                ->where('model_type', $modelClass)
                ->where('model_id', (string) $modelId)
                ->first();

            $oldTextFlat = $oldResult->text_concat ?? '';
            $oldWords = $this->tokenizeText($oldTextFlat);
        }

        $colNames = implode(', ', array_keys($weightCols));
        $placeholders = implode(', ', array_fill(0, count($weightCols), '?'));
        $updateParts = collect($weightCols)->keys()->map(fn ($c) => "{$c} = VALUES({$c})")->implode(', ');

        DB::connection($this->connection)->statement("
            INSERT INTO " . $this->table(self::TABLE) . " (model_type, model_id, {$colNames}, last_synced_at)
            VALUES (?, ?, {$placeholders}, NOW())
            ON DUPLICATE KEY UPDATE {$updateParts}, last_synced_at = NOW()
        ", [$modelClass, (string) $modelId, ...array_values($weightCols)]);

        if (! $this->isRebuilding) {
            $this->synchronizeVocabCounts($oldWords, $newWords);
        }

        $this->searchCache->clear();
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $firstDoc = $this->buildSearchText($modelClass, $documents[0]['document']);
        $colNames = implode(', ', array_keys($firstDoc)) . ', last_synced_at';
        $valuePlaceholders = '(' . implode(', ', array_fill(0, count($firstDoc) + 2, '?')) . ', NOW())';

        $values = [];
        $params = [];

        foreach ($documents as $doc) {
            $modelId = (string) $doc['model_id'];
            $weightCols = $this->buildSearchText($modelClass, $doc['document']);

            $params[] = $modelClass;
            $params[] = $modelId;
            foreach ($weightCols as $val) {
                $params[] = $val;
            }
            $values[] = $valuePlaceholders;
        }

        $placeholders = implode(', ', $values);
        $updateParts = collect($firstDoc)->keys()->map(fn ($c) => "{$c} = VALUES({$c})")->implode(', ') . ', last_synced_at = NOW()';

        DB::connection($this->connection)->statement("
            INSERT INTO " . $this->table(self::TABLE) . " (model_type, model_id, {$colNames})
            VALUES {$placeholders}
            ON DUPLICATE KEY UPDATE {$updateParts}
        ", $params);

        if (! $this->isRebuilding) {
            foreach ($documents as $doc) {
                $weightCols = $this->buildSearchText($modelClass, $doc['document']);
                $newTextFlat = trim(implode(' ', $weightCols));
                $newWords = $this->tokenizeText($newTextFlat);
                $this->synchronizeVocabCounts([], $newWords);
            }
        }

        $this->searchCache->clear();
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $concatExpr = $this->concatWeightColumns();

        $oldResult = DB::connection($this->connection)
            ->table($this->table(self::TABLE))
            ->select(DB::raw("{$concatExpr} AS text_concat"))
            ->where('model_type', $modelClass)
            ->where('model_id', (string) $modelId)
            ->first();

        $oldText = $oldResult->text_concat ?? '';

        DB::connection($this->connection)->delete(
            'DELETE FROM ' . $this->table(self::TABLE) . ' WHERE model_type = ? AND model_id = ?',
            [$modelClass, (string) $modelId],
        );

        if ($oldText) {
            $words = $this->tokenizeText($oldText);
            foreach ($words as $word) {
                DB::connection($this->connection)
                    ->table($this->table(self::VOCAB_TABLE))
                    ->where('word', $word)
                    ->decrement('doc_count');
            }

            DB::connection($this->connection)
                ->table($this->table(self::VOCAB_TABLE))
                ->where('doc_count', '<=', 0)
                ->delete();
        }

        $this->searchCache->clear();
    }

    // ─── Search ──────────────────────────────────────────

    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        if (empty(trim($query))) {
            return [];
        }

        // Try cache first
        $cacheKey = $this->searchCache->key($query . (app(TenantManager::class)->tenantId() ?? ''), $modelClasses, $limit, $offset, $mode);
        $cached = $this->searchCache->get($cacheKey);

        if ($cached !== null) {
            return array_map(fn ($r) => Result::fromRaw($r), $cached);
        }

        $safeQuery = $this->normalizeQuery($query);
        $booleanQuery = $this->toBooleanMode($safeQuery, $mode);

        if (empty(trim($booleanQuery))) {
            return [];
        }

        $modelTypes = array_map(fn ($c) => (string) $c, $modelClasses);
        [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelTypes);

        $match = $this->buildWeightMatchExpressions();

        $firstCol = $this->getFirstTextColumn();
        $titleCol = $this->getTitleColumn() ?: $this->getFirstTextColumn() ?: 'text_w1';

        $selectBindings = array_fill(0, $match['bindCount'], $booleanQuery);
        $whereBindings = array_fill(0, $match['bindCount'], $booleanQuery);

        $rows = DB::connection($this->connection)->select("
            SELECT model_type, model_id,
                   {$firstCol} AS search_text,
                   {$titleCol} AS search_title,
                   {$match['selectExpr']} AS rank,
                   COUNT(*) OVER () AS total_count
             FROM " . $this->table(self::TABLE) . "
             WHERE model_type IN ({$inPlaceholders})
               AND {$match['whereExpr']}
             ORDER BY rank DESC
             LIMIT ? OFFSET ?
        ", array_merge(
            $selectBindings,
            $inParams,
            $whereBindings,
            [$limit, $offset],
        ));

        $results = [];

        foreach ($rows as $row) {
            $score = $this->normalizeScore((float) $row->rank, null, 1);

            $results[] = [
                'modelClass' => $row->model_type,
                'modelId' => ctype_digit($row->model_id) ? (int) $row->model_id : $row->model_id,
                'rank' => $score,
                'title' => $row->search_title ?? $row->model_id,
                'row' => [
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                    'search_text' => $row->search_text ?? '',
                ],
                'totalCount' => (int) ($row->total_count ?? 0),
            ];
        }

        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $safeQuery);
        }

        // Cache results
        $this->searchCache->set($cacheKey, $results);

        return array_map(fn ($r) => Result::fromRaw($r), $results);
    }

    public function count(string $query, array $modelClasses): int
    {
        if (empty(trim($query))) {
            return 0;
        }

        $mode = config('illumi-search.processing.mode', 'advanced');
        $safeQuery = $this->normalizeQuery($query);
        $booleanQuery = $this->toBooleanMode($safeQuery, $mode);

        if (empty(trim($booleanQuery))) {
            return 0;
        }

        $modelTypes = array_map(fn ($c) => (string) $c, $modelClasses);
        [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelTypes);

        $match = $this->buildWeightMatchExpressions();

        $whereBindings = array_fill(0, $match['bindCount'], $booleanQuery);

        $row = DB::connection($this->connection)->selectOne("
            SELECT COUNT(*) AS cnt
            FROM " . $this->table(self::TABLE) . "
            WHERE model_type IN ({$inPlaceholders})
              AND {$match['whereExpr']}
        ", array_merge($inParams, $whereBindings));

        return (int) ($row->cnt ?? 0);
    }

    public function optimize(): array
    {
        DB::connection($this->connection)->statement('OPTIMIZE TABLE ' . $this->table(self::TABLE));
        $size = $this->getDatabaseSize();

        return [
            'vacuum' => ['before' => $size, 'after' => $size],
            'tables_optimized' => 1,
        ];
    }

    // ─── Config (Meta table)

    public function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            $row = DB::connection($this->connection)->selectOne(
                'SELECT `value` FROM ' . $this->table(self::CONFIG_TABLE) . ' WHERE `key` = ?',
                [$key],
            );
        } catch (\Exception) {
            return $default;
        }

        if ($row === null) {
            return $default;
        }

        return ConfigHelper::decode($row->value, $default);
    }

    public function setConfig(string $key, mixed $value): void
    {
        try {
            DB::connection($this->connection)->statement(
                'INSERT INTO ' . $this->table(self::CONFIG_TABLE) . ' (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$key, ConfigHelper::encode($value)],
            );
        } catch (\Exception $e) {
            logger()->debug('illumi-search mysql: failed to set config "{key}" — table may not exist yet', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Integrity ───────────────────────────────────────

    public function integrityCheck(string $modelClass): bool
    {
        $row = DB::connection($this->connection)->selectOne('CHECK TABLE ' . $this->table(self::TABLE));

        return ($row->Msg_type ?? '') !== 'Error';
    }

    public function fullIntegrityCheck(): array
    {
        $results = DB::connection($this->connection)->select('CHECK TABLE ' . $this->table(self::TABLE));

        $errors = [];
        foreach ($results as $r) {
            if (($r->Msg_type ?? '') === 'Error') {
                $errors[] = $r->Msg_text ?? 'Unknown CHECK TABLE error';
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }

    public function getEngineStatus(): array
    {
        return [
            'driver' => 'MySQL FULLTEXT',
            'engine_version' => $this->getEngineVersion(),
            'connection' => $this->getDatabasePath(),
            'database_size' => $this->getDatabaseSize(),
            'max_weight' => config('illumi-search.processing.max_weight', 3),
            'collation' => 'utf8mb4_unicode_ci',
            'vocab_limit' => config('illumi-search.spellcheck.vocab_limit', 5000),
        ];
    }

    public function setRebuilding(bool $isRebuilding): void
    {
        $this->isRebuilding = $isRebuilding;
    }

    // ─── Spellcheck ───────────────────────────────────────

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        if (strlen(trim($query)) < 2) {
            return [];
        }

        $queryAscii = (string) (new UnicodeString($query))->ascii();
        $queryScripts = $this->scriptsOf($query);
        $queryTrigrams = $this->wordToTrigrams($queryAscii);

        if (count($queryTrigrams) < 2) {
            return [];
        }

        // Phase 1: trigram matching — no Levenshtein, no limit cap
        $trigramWords = DB::connection($this->connection)
            ->table($this->table(self::TRIGRAM_TABLE))
            ->select('word', DB::raw('AVG(doc_count) AS avg_doc'))
            ->whereIn('trigram', $queryTrigrams)
            ->groupBy('word')
            ->havingRaw('COUNT(*) >= ?', [min(2, count($queryTrigrams))])
            ->orderByDesc('avg_doc')
            ->limit($limit * 3)
            ->pluck('word');

        if ($trigramWords->isNotEmpty()) {
            $vocab = DB::connection($this->connection)
                ->table($this->table(self::VOCAB_TABLE))
                ->whereIn('word', $trigramWords)
                ->get(['word', 'ascii_word']);

            $suggestions = $this->rankSuggestions($vocab, $queryAscii, $queryScripts, $maxDistance);

            if (count($suggestions) >= $limit) {
                return array_slice($suggestions, 0, $limit);
            }
        }

        // Phase 2: fallback prefix Levenshtein (if trigrams didn't yield enough)
        $prefix = mb_substr($queryAscii, 0, 2);
        $vocabLimit = (int) config('illumi-search.spellcheck.vocab_limit', 5000);

        $fallback = DB::connection($this->connection)
            ->table($this->table(self::VOCAB_TABLE))
            ->where('doc_count', '>=', 1)
            ->where('ascii_word', 'like', $prefix . '%')
            ->whereNotIn('word', $trigramWords)
            ->orderBy('doc_count', 'desc')
            ->limit($vocabLimit)
            ->get(['word', 'ascii_word']);

        $more = $this->rankSuggestions($fallback, $queryAscii, $queryScripts, $maxDistance);

        return array_slice(array_merge($suggestions ?? [], $more), 0, $limit);
    }

    /**
     * Score and rank words by Levenshtein distance + script penalty.
     *
     * @param  Collection  $vocab  Collection of {word, ascii_word}
     * @return string[]
     */
    private function rankSuggestions($vocab, string $queryAscii, array $queryScripts, int $maxDistance): array
    {
        $scriptCache = [];

        return $vocab
            ->map(function ($row) use ($queryAscii, &$scriptCache) {
                $asciiWord = $row->ascii_word;

                if (! isset($scriptCache[$asciiWord])) {
                    $scriptCache[$asciiWord] = $this->scriptsOf($row->word);
                }

                return [
                    'word' => $row->word,
                    'distance' => levenshtein($queryAscii, $asciiWord),
                    'scripts' => $scriptCache[$asciiWord],
                ];
            })
            ->filter(fn ($w) => $w['distance'] > -1 && $w['distance'] <= $maxDistance)
            ->map(fn ($w) => [
                'word' => $w['word'],
                'score' => $w['distance']
                    + (empty(array_intersect($queryScripts, $w['scripts'])) ? self::SCRIPT_MISMATCH_PENALTY : 0),
            ])
            ->sortBy('score')
            ->pluck('word')
            ->all();
    }

    public function getSupportedOperators(): array
    {
        return ['AND', 'OR', 'NOT'];
    }

    public function supportsPhraseSearch(): bool
    {
        return true;
    }

    public function supportsPrefixWildcard(): bool
    {
        return true;
    }

    /**
     * @param  string[]  $oldWords
     * @param  string[]  $newWords
     */
    private function synchronizeVocabCounts(array $oldWords, array $newWords): void
    {
        $added = array_diff($newWords, $oldWords);

        if (! empty($added)) {
            $values = [];
            $params = [];
            foreach ($added as $word) {
                if ($word === '') {
                    continue;
                }
                $ascii = (string) (new UnicodeString($word))->ascii();
                $values[] = '(?, ?, ?)';
                $params[] = $word;
                $params[] = $ascii;
                $params[] = 1;
                $this->syncWordTrigrams($word, $ascii, 1);
            }

            if (! empty($values)) {
                DB::connection($this->connection)->statement(
                    'INSERT INTO ' . $this->table(self::VOCAB_TABLE) . ' (word, ascii_word, doc_count)
                     VALUES ' . implode(', ', $values) . '
                     ON DUPLICATE KEY UPDATE doc_count = doc_count + VALUES(doc_count)',
                    $params,
                );
            }
        }

        foreach (array_diff($oldWords, $newWords) as $word) {
            if ($word === '') {
                continue;
            }
            $ascii = (string) (new UnicodeString($word))->ascii();
            DB::connection($this->connection)
                ->table($this->table(self::VOCAB_TABLE))
                ->where('word', $word)
                ->decrement('doc_count');
            $this->syncWordTrigrams($word, $ascii, -1);
        }

        DB::connection($this->connection)
            ->table($this->table(self::VOCAB_TABLE))
            ->where('doc_count', '<=', 0)
            ->delete();

        DB::connection($this->connection)
            ->table($this->table(self::TRIGRAM_TABLE))
            ->where('doc_count', '<=', 0)
            ->delete();
    }

    private function syncWordTrigrams(string $word, string $asciiWord, int $delta): void
    {
        $trigrams = $this->wordToTrigrams($asciiWord);

        foreach ($trigrams as $t) {
            if ($delta > 0) {
                DB::connection($this->connection)->statement(
                    'INSERT INTO ' . $this->table(self::TRIGRAM_TABLE) . ' (trigram, word, doc_count)
                     VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE doc_count = doc_count + 1',
                    [$t, $word],
                );
            } else {
                DB::connection($this->connection)->statement(
                    'UPDATE ' . $this->table(self::TRIGRAM_TABLE) . '
                     SET doc_count = doc_count - 1
                     WHERE trigram = ? AND word = ?',
                    [$t, $word],
                );
            }
        }
    }

    public function resetVocab(): void
    {
        DB::connection($this->connection)
            ->table($this->table(self::VOCAB_TABLE))
            ->truncate();
    }

    /**
     * Atomically swap a table with a newly built replacement.
     *
     * Creates a temp table, runs the builder callback to populate it,
     * then RENAME swaps (atomic by MySQL guarantee) and drops the old table.
     * Provides crash recovery via DROP IF EXISTS for leftover temps.
     */
    private function atomicSwapBuild(
        string $tableName,
        string $createSql,
        callable $builder,
    ): void {
        $temp = $tableName . '_new';
        $old = $tableName . '_old';

        // Drop any leftover tables from a previous crash
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$old}");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$temp}");

        // Create the temp table with the same structure
        DB::connection($this->connection)->statement(
            str_replace('{table}', $temp, $createSql),
        );

        // Build data into the temp via the caller's logic
        $builder($temp);

        // Atomic swap: RENAME TABLE is guaranteed atomic by MySQL
        // If the target table doesn't exist yet (first run), skip the swap
        $targetExists = DB::connection($this->connection)
            ->selectOne("SELECT 1 FROM information_schema.tables WHERE table_name = ?", [$tableName]);

        if ($targetExists) {
            DB::connection($this->connection)->statement("
                RENAME TABLE
                    {$tableName} TO {$old},
                    {$temp} TO {$tableName}
            ");
            DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$old}");
        } else {
            // First build — just rename temp to target
            DB::connection($this->connection)->statement("RENAME TABLE {$temp} TO {$tableName}");
            DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$old}");
        }
    }

    public function rebuildVocabFromScratch(): void
    {
        $createSql = "
            CREATE TABLE IF NOT EXISTS {table} (
                word VARCHAR(255) NOT NULL UNIQUE,
                ascii_word VARCHAR(255) NOT NULL DEFAULT '',
                doc_count INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->atomicSwapBuild($this->table(self::VOCAB_TABLE), $createSql, function ($temp) {
            $textExpr = $this->concatWeightColumns();

            // Adaptive chunk size: ~500 KB per chunk
            $avgLength = (int) DB::connection($this->connection)
                ->table($this->table(self::TABLE))
                ->avg(DB::raw('LENGTH(' . $textExpr . ')'));
            $chunkSize = max(100, min(2000, (int) (500000 / max($avgLength, 1))));

            $stopwordsLookup = array_flip($this->loadStopwords());

            DB::connection($this->connection)
                ->table($this->table(self::TABLE))
                ->select('id', 'model_type', 'model_id', DB::raw("{$textExpr} AS search_text"))
                ->chunkById($chunkSize, function ($rows) use ($stopwordsLookup, $temp) {
                    $batch = [];

                    foreach ($rows as $row) {
                        $text = trim($row->search_text ?? '');
                        if ($text === '') {
                            continue;
                        }

                        $words = $this->tokenizeText($text);

                        foreach ($words as $word) {
                            if (isset($stopwordsLookup[$word])) {
                                continue;
                            }

                            if (! isset($batch[$word])) {
                                $batch[$word] = [
                                    'word' => $word,
                                    'doc_count' => 0,
                                    'ascii_word' => (string) (new UnicodeString($word))->ascii(),
                                ];
                            }
                            $batch[$word]['doc_count']++;
                        }
                    }

                    if (! empty($batch)) {
                        $values = [];
                        $params = [];
                        foreach ($batch as $row) {
                            $values[] = '(?, ?, ?)';
                            $params[] = $row['word'];
                            $params[] = $row['ascii_word'];
                            $params[] = $row['doc_count'];
                        }

                        DB::connection($this->connection)->statement(
                            'INSERT INTO ' . $temp . ' (word, ascii_word, doc_count)
                             VALUES ' . implode(', ', $values) . '
                             ON DUPLICATE KEY UPDATE doc_count = doc_count + VALUES(doc_count)',
                            $params,
                        );
                    }
                });
        });
    }

    public function rebuildTrigramTable(): void
    {
        $createSql = '
            CREATE TABLE IF NOT EXISTS {table} (
                trigram   CHAR(3) NOT NULL,
                word      VARCHAR(255) NOT NULL,
                doc_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (trigram, word),
                INDEX idx_word (word)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ';

        $this->atomicSwapBuild($this->table(self::TRIGRAM_TABLE), $createSql, function ($temp) {
            DB::connection($this->connection)
                ->table($this->table(self::VOCAB_TABLE))
                ->orderBy('doc_count', 'desc')
                ->chunk(2000, function ($rows) use ($temp) {
                    $values = [];
                    $params = [];

                    foreach ($rows as $row) {
                        $trigrams = $this->wordToTrigrams($row->ascii_word);

                        foreach ($trigrams as $t) {
                            $values[] = '(?, ?, ?)';
                            $params[] = $t;
                            $params[] = $row->word;
                            $params[] = $row->doc_count;
                        }
                    }

                    if (! empty($values)) {
                        DB::connection($this->connection)->statement(
                            'INSERT INTO ' . $temp . ' (trigram, word, doc_count)
                             VALUES ' . implode(', ', $values) . '
                             ON DUPLICATE KEY UPDATE doc_count = doc_count + VALUES(doc_count)',
                            $params,
                        );
                    }
                });
        });
    }

    /**
     * Rebuild the entire search_index from scratch using atomic swap.
     * Iterates all indexed model classes, processes documents, and
     * bulk-inserts into a replacement table, then atomically swaps.
     */
    public function rebuildIndexFromScratch(): void
    {
        $maxWeight = (int) config('illumi-search.processing.max_weight', 3);

        $createSql = "
            CREATE TABLE IF NOT EXISTS {table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_type VARCHAR(255) NOT NULL,
                model_id VARCHAR(255) NOT NULL,
                last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_model_model_id (model_type, model_id),
                INDEX idx_model_type (model_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $modelClasses = $this->getIndexedModelClasses();

        if (empty($modelClasses)) {
            return;
        }

        $this->atomicSwapBuild($this->table(self::TABLE), $createSql, function ($temp) use ($modelClasses, $maxWeight) {
            // Add weight columns to the temp table
            $this->ensureWeightColumnsExist($maxWeight);

            // Force column cache refresh so getExistingWeightColumns sees new columns
            $weightCols = $this->getExistingWeightColumns(true);
            $weightColNames = implode(', ', $weightCols);

            // Re-read config for the correct max_weight
            $currentMax = (int) config('illumi-search.processing.max_weight', 3);

            $textProcessor = app(TextProcessor::class);

            foreach ($modelClasses as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                $instance = new $class;

                $instance->chunkById(config('illumi-search.indexing.rebuild_batch_size', 100), function ($models) use ($temp, $class, $weightColNames, $currentMax, $textProcessor) {
                    $valuePlaceholders = '(' . implode(', ', array_fill(0, 2 + $currentMax, '?')) . ')';
                    $values = [];
                    $params = [];

                    foreach ($models as $model) {
                        $document = method_exists($class, 'processDocument')
                            ? $class::processDocument($model, $textProcessor)
                            : ['title' => $model->title ?? '', 'body' => $model->body ?? ''];

                        $weightCols = $this->buildSearchText($class, $document);

                        $params[] = $class;
                        $params[] = (string) $model->getKey();
                        for ($w = 1; $w <= $currentMax; $w++) {
                            $params[] = $weightCols["text_w{$w}"] ?? '';
                        }
                        $values[] = $valuePlaceholders;
                    }

                    if (! empty($values)) {
                        DB::connection($this->connection)->statement(
                            'INSERT IGNORE INTO ' . $temp . " (model_type, model_id, {$weightColNames})
                             VALUES " . implode(', ', $values),
                            $params,
                        );
                    }
                });
            }
        });
    }

    private function loadStopwords(): array
    {
        $languages = config('illumi-search.processing.stopwords', []);

        if (! is_array($languages) || empty($languages)) {
            return [];
        }

        $filter = new StopwordFilter;
        $all = [];

        foreach ($languages as $lang) {
            if (is_string($lang) && $lang !== '') {
                $all = array_merge($all, $filter->load($lang));
            }
        }

        return array_values(array_unique($all));
    }

    // ─── Text Processing ────────────────────────────────

    /**
     * Build an array of column => text, keyed by weight level.
     * Each weight level gets its own FULLTEXT column for precise BM25 ranking.
     *
     * @param  array<string, string>  $document
     * @return array<string, string>
     */
    private function buildSearchText(string $modelClass, array $document): array
    {
        if (! isset(self::$checkedSearchable[$modelClass])) {
            self::$checkedSearchable[$modelClass] = method_exists($modelClass, 'getSearchableColumns');
        }

        if (self::$checkedSearchable[$modelClass]) {
            $searchable = (new $modelClass)->getSearchableColumns();
        } else {
            $searchable = [];
        }

        $maxWeight = (int) config('illumi-search.processing.max_weight', 3);
        $result = [];

        for ($w = 1; $w <= $maxWeight; $w++) {
            $result["text_w{$w}"] = '';
        }

        foreach ($searchable as $key => $config) {
            $col = is_array($config) ? $key : $config;
            $weight = (int) ($config['weight'] ?? 1);
            $weight = max(1, min($maxWeight, $weight));

            $val = $document[$col] ?? '';
            if ($val === '') {
                continue;
            }

            $targetCol = "text_w{$weight}";
            $result[$targetCol] .= ' ' . $val;
        }

        // Fallback: put everything in text_w1
        if (empty(array_filter($result))) {
            $result['text_w1'] = ' ' . implode(' ', $document);
        }

        // Trim each column
        foreach ($result as $col => $val) {
            $result[$col] = trim($val);
        }

        return $result;
    }

    /**
     * @return array{columns: string[], selectExpr: string, whereExpr: string, bindCount: int}
     */
    private function buildWeightMatchExpressions(): array
    {
        $weightCols = $this->getExistingWeightColumns();

        $selectParts = [];
        $whereParts = [];

        foreach ($weightCols as $i => $col) {
            $weight = $i + 1;
            $selectParts[] = "MATCH({$col}) AGAINST(? IN BOOLEAN MODE) * {$weight}";
            $whereParts[] = "MATCH({$col}) AGAINST(? IN BOOLEAN MODE)";
        }

        return [
            'columns' => $weightCols,
            'selectExpr' => implode(' + ', $selectParts),
            'whereExpr' => '(' . implode(' OR ', $whereParts) . ')',
            'bindCount' => count($weightCols),
        ];
    }

    private function getFirstTextColumn(): string
    {
        return $this->getExistingWeightColumns()[0] ?? '';
    }

    /**
     * Return the highest-weight text column (= the one that usually holds the title).
     */
    private function getTitleColumn(): string
    {
        $cols = $this->getExistingWeightColumns();
        $lastKey = array_key_last($cols);

        return $lastKey !== null ? $cols[$lastKey] : '';
    }

    private function modelTypePlaceholders(array $classes): array
    {
        return [
            collect($classes)->map(fn () => '?')->implode(','),
            collect($classes)->map(fn ($c) => (string) $c)->toArray(),
        ];
    }

    private function toBooleanMode(string $query, string $mode): string
    {
        if ($mode === 'raw') {
            return $query;
        }

        $terms = OperatorRegistry::tokenize($query);
        $parts = [];
        $pendingOperator = '';

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            $upper = strtoupper($term);

            if (OperatorRegistry::isOperator($term)) {
                $pendingOperator = match ($upper) {
                    'AND', 'NEAR' => '+',
                    'NOT' => '-',
                    'OR' => '',
                };

                continue;
            }

            if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
                $parts[] = $pendingOperator . $term;
                $pendingOperator = '';

                continue;
            }

            $clean = Str::of($term)->replaceMatches('/[^\p{L}\p{N}\*\-]/u', '')->toString();

            // Skip terms that are only operators (empty, single asterisk, dashes)
            if ($clean === '' || $clean === '-' || $clean === '--' || $clean === '*' || preg_match('/^[\*\-\+]+$/', $clean)) {
                continue;
            }

            if ($mode === 'basic' && ! str_starts_with($clean, '"')) {
                $clean = rtrim($clean, '*') . '*';
            }

            $operator = $pendingOperator;
            $pendingOperator = '';

            $withWildcard = rtrim($clean, '*') . '*';
            $needsQuoting = preg_match('/[^a-zA-Z0-9\*\-]+/', $clean);
            $parts[] = $needsQuoting ? $operator . "\"{$clean}\"" : $operator . $withWildcard;
        }

        if ($pendingOperator === '+') {
            $parts[] = '+';
        }

        return implode(' ', $parts);
    }
}

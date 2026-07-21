<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\SnippetService;
use Symfony\Component\String\UnicodeString;

class MySqlEngine implements Engine
{
    private const TABLE = 'search_index';

    private const CONFIG_TABLE = 'search_config';

    private const VOCAB_TABLE = 'search_vocab';

    public const CONNECTION_NAME = 'illumi-search-mysql';

    private bool $tableCreated = false;

    private ?TextProcessor $textProcessor = null;

    private string $connection = self::CONNECTION_NAME;

    private ?SnippetService $snippets = null;

    /** @var array<string, bool> */
    private static array $checkedSearchable = [];

    public function __construct(?SnippetService $snippets = null)
    {
        $this->registerConnection();
        $this->snippets = $snippets;
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
            'host' => config('illumi-search.mysql.host', '127.0.0.1'),
            'port' => config('illumi-search.mysql.port', '3306'),
            'database' => config('illumi-search.mysql.database', 'illumi_search'),
            'username' => config('illumi-search.mysql.username', 'root'),
            'password' => config('illumi-search.mysql.password', ''),
            'unix_socket' => config('illumi-search.mysql.unix_socket', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);
    }

    public function isFts5Available(): bool
    {
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

    public function getPragma(string $name): string|int|null
    {
        return null;
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

    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        return [];
    }

    public function vacuum(): void
    {
    }

    // ─── Schema ─────────────────────────────────────────

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        if ($this->tableCreated) {
            return;
        }

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_type VARCHAR(255) NOT NULL,
                model_id VARCHAR(255) NOT NULL,
                search_text LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_model_model_id (model_type, model_id),
                FULLTEXT INDEX idx_fts (search_text)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . self::CONFIG_TABLE . ' (
                `key` VARCHAR(255) PRIMARY KEY,
                `value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . self::VOCAB_TABLE . ' (
                word VARCHAR(255) NOT NULL UNIQUE,
                ascii_word VARCHAR(255) NOT NULL DEFAULT \'\',
                doc_count INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $this->tableCreated = true;
    }

    /**
     * Delete all indexed documents for a model class.
     * (Semantically equivalent to dropping an FTS5 virtual table in
     * SqliteEngine — all data for this model is removed from the shared
     * search_index table.)
     */
    public function dropTable(string $modelClass): void
    {
        DB::connection($this->connection)->delete(
            'DELETE FROM ' . self::TABLE . ' WHERE model_type = ?',
            [$modelClass],
        );
    }

    public function dropIndexTable(string $modelClass): void
    {
        $this->dropTable($modelClass);
    }

    public function tableName(string $modelClass): string
    {
        return self::TABLE;
    }

    public function tableExists(string $modelClass): bool
    {
        return $this->tableExistsAny();
    }

    /**
     * Check if the search_index table exists in MySQL.
     * Note: this is a global check (table exists at all), not per-model.
     */
    private function tableExistsAny(): bool
    {
        $row = DB::connection($this->connection)->selectOne('
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ', [self::TABLE]);

        return $row !== null;
    }

    public function listIndexTables(): array
    {
        return $this->tableExistsAny() ? [self::TABLE] : [];
    }

    public function getIndexStats(): array
    {
        $rows = DB::connection($this->connection)->select('
            SELECT model_type, COUNT(*) AS record_count, MAX(created_at) AS last_synced_at
            FROM ' . self::TABLE . '
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
        return DB::connection($this->connection)->table(self::TABLE)
            ->select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->all();
    }

    // ─── CRUD ───────────────────────────────────────────

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        // Read old text BEFORE upsert to compute vocab diff
        $oldText = DB::connection($this->connection)
            ->table(self::TABLE)
            ->where('model_type', $modelClass)
            ->where('model_id', (string) $modelId)
            ->value('search_text');

        $oldWords = $this->splitAndCleanText($oldText);
        $newText = $this->buildSearchText($modelClass, $document);
        $newWords = $this->splitAndCleanText($newText);

        DB::connection($this->connection)->statement('
            INSERT INTO ' . self::TABLE . ' (model_type, model_id, search_text)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE search_text = VALUES(search_text)
        ', [$modelClass, (string) $modelId, $newText]);

        $this->applyVocabDiff($oldWords, $newWords);
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $values = [];
        $params = [];
        $vocabDiffs = [];

        foreach ($documents as $doc) {
            $modelId = (string) $doc['model_id'];

            $oldText = DB::connection($this->connection)
                ->table(self::TABLE)
                ->where('model_type', $modelClass)
                ->where('model_id', $modelId)
                ->value('search_text');

            $oldWords = $this->splitAndCleanText($oldText);
            $newText = $this->buildSearchText($modelClass, $doc['document']);
            $newWords = $this->splitAndCleanText($newText);

            $params[] = $modelClass;
            $params[] = $modelId;
            $params[] = $newText;
            $values[] = '(?, ?, ?)';
            $vocabDiffs[] = ['old' => $oldWords, 'new' => $newWords];
        }

        $placeholders = implode(', ', $values);

        DB::connection($this->connection)->statement('
            INSERT INTO ' . self::TABLE . ' (model_type, model_id, search_text)
            VALUES ' . $placeholders . '
            ON DUPLICATE KEY UPDATE search_text = VALUES(search_text)
        ', $params);

        foreach ($vocabDiffs as $diff) {
            $this->applyVocabDiff($diff['old'], $diff['new']);
        }
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $oldText = DB::connection($this->connection)
            ->table(self::TABLE)
            ->where('model_type', $modelClass)
            ->where('model_id', (string) $modelId)
            ->value('search_text');

        DB::connection($this->connection)->delete(
            'DELETE FROM ' . self::TABLE . ' WHERE model_type = ? AND model_id = ?',
            [$modelClass, (string) $modelId],
        );

        if ($oldText) {
            $words = $this->splitAndCleanText($oldText);
            foreach ($words as $word) {
                DB::connection($this->connection)
                    ->table(self::VOCAB_TABLE)
                    ->where('word', $word)
                    ->decrement('doc_count');
            }

            DB::connection($this->connection)
                ->table(self::VOCAB_TABLE)
                ->where('doc_count', '<=', 0)
                ->delete();
        }
    }

    // ─── Search ──────────────────────────────────────────

    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        if (empty(trim($query))) {
            return [];
        }

        $safeQuery = $this->normalizeQuery($query);
        $booleanQuery = $this->toBooleanMode($safeQuery, $mode);

        $modelTypes = array_map(fn ($c) => (string) $c, $modelClasses);
        [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelTypes);

        $rows = DB::connection($this->connection)->select('
            SELECT model_type, model_id, search_text,
                   MATCH(search_text) AGAINST(? IN BOOLEAN MODE) AS rank,
                   COUNT(*) OVER () AS total_count
             FROM ' . self::TABLE . '
             WHERE model_type IN (' . $inPlaceholders . ')
               AND MATCH(search_text) AGAINST(? IN BOOLEAN MODE)
             ORDER BY rank DESC
             LIMIT ? OFFSET ?
        ', array_merge(
            [$booleanQuery],
            $inParams,
            [$booleanQuery],
            [$limit, $offset],
        ));

        $results = [];

        foreach ($rows as $row) {
            $score = round((float) $row->rank, 8);

            $results[] = [
                'modelClass' => $row->model_type,
                'modelId' => ctype_digit($row->model_id) ? (int) $row->model_id : $row->model_id,
                'rank' => $score,
                'title' => $row->model_id,
                'row' => [
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                    'search_text' => $row->search_text,
                ],
                'totalCount' => (int) ($row->total_count ?? 0),
            ];
        }

        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $safeQuery);
        }

        return array_map(
            fn ($r) => Result::make(
                modelClass: $r['modelClass'],
                modelId: $r['modelId'],
                rank: $r['rank'],
                title: $r['title'],
                summary: $r['summary'] ?? null,
                raw: $r['row'],
                totalCount: $r['totalCount'],
            ),
            $results,
        );
    }

    public function count(string $query, array $modelClasses): int
    {
        if (empty(trim($query))) {
            return 0;
        }

        $mode = config('illumi-search.mode', 'advanced');
        $safeQuery = $this->normalizeQuery($query);
        $booleanQuery = $this->toBooleanMode($safeQuery, $mode);
        $modelTypes = array_map(fn ($c) => (string) $c, $modelClasses);
        [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelTypes);

        $row = DB::connection($this->connection)->selectOne('
            SELECT COUNT(*) AS cnt
            FROM ' . self::TABLE . '
            WHERE model_type IN (' . $inPlaceholders . ')
              AND MATCH(search_text) AGAINST(? IN BOOLEAN MODE)
        ', array_merge($inParams, [$booleanQuery]));

        return (int) ($row->cnt ?? 0);
    }

    public function optimize(): array
    {
        DB::connection($this->connection)->statement('OPTIMIZE TABLE ' . self::TABLE);
        $size = $this->getDatabaseSize();

        return [
            'vacuum' => ['before' => $size, 'after' => $size],
            'tables_optimized' => 1,
        ];
    }

    // ─── Config (Meta table)
    // MySQL uses its own CONFIG TABLE via the connection, not a SQLite file

    public function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            $row = DB::connection($this->connection)->selectOne(
                'SELECT `value` FROM ' . self::CONFIG_TABLE . ' WHERE `key` = ?',
                [$key],
            );
        } catch (\Exception) {
            return $default;
        }

        if ($row === null) {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return $decoded !== null ? $decoded : $row->value;
    }

    public function setConfig(string $key, mixed $value): void
    {
        try {
            DB::connection($this->connection)->statement(
                'INSERT INTO ' . self::CONFIG_TABLE . ' (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$key, is_string($value) ? $value : json_encode($value)],
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
        $row = DB::connection($this->connection)->selectOne('CHECK TABLE ' . self::TABLE);

        return ($row->Msg_type ?? '') !== 'Error';
    }

    public function fullIntegrityCheck(): array
    {
        $results = DB::connection($this->connection)->select('CHECK TABLE ' . self::TABLE);

        $errors = [];
        foreach ($results as $r) {
            if (($r->Msg_type ?? '') === 'Error') {
                $errors[] = $r->Msg_text ?? 'Unknown CHECK TABLE error';
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }

    // ─── Spellcheck ───────────────────────────────────────

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        if (strlen(trim($query)) < 2) {
            return [];
        }

        $queryAscii = (string) (new UnicodeString($query))->ascii();

        $words = DB::connection($this->connection)
            ->table(self::VOCAB_TABLE)
            ->where('doc_count', '>=', 1)
            ->orderBy('doc_count', 'desc')
            ->get(['word', 'ascii_word']);

        $suggestions = $words
            ->map(fn ($row): array => [
                'word' => $row->word,
                'distance' => levenshtein($queryAscii, $row->ascii_word),
            ])
            ->filter(fn ($w) => $w['distance'] !== -1 && $w['distance'] <= $maxDistance)
            ->sortBy('distance')
            ->take(5)
            ->pluck('word')
            ->all();

        return $suggestions;
    }

    /**
     * @param string[] $oldWords
     * @param string[] $newWords
     */
    protected function applyVocabDiff(array $oldWords, array $newWords): void
    {
        foreach (array_diff($newWords, $oldWords) as $word) {
            if ($word === '') {
                continue;
            }

            $ascii = (string) (new UnicodeString($word))->ascii();

            DB::connection($this->connection)
                ->table(self::VOCAB_TABLE)
                ->updateOrInsert(
                    ['word' => $word],
                    ['doc_count' => DB::raw('doc_count + 1'), 'ascii_word' => $ascii],
                );
        }

        foreach (array_diff($oldWords, $newWords) as $word) {
            if ($word === '') {
                continue;
            }
            DB::connection($this->connection)
                ->table(self::VOCAB_TABLE)
                ->where('word', $word)
                ->decrement('doc_count');
        }

        DB::connection($this->connection)
            ->table(self::VOCAB_TABLE)
            ->where('doc_count', '<=', 0)
            ->delete();
    }

    public function resetVocab(): void
    {
        DB::connection($this->connection)
            ->table(self::VOCAB_TABLE)
            ->truncate();
    }

    public function rebuildVocabFromScratch(): void
    {
        $temp = self::VOCAB_TABLE . '_new';
        $old  = self::VOCAB_TABLE . '_old';
        $templateSql = "
            CREATE TABLE IF NOT EXISTS {table} (
                word VARCHAR(255) NOT NULL UNIQUE,
                ascii_word VARCHAR(255) NOT NULL DEFAULT '',
                doc_count INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Drop any leftover tables from a previous crash
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$old}");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$temp}");

        DB::connection($this->connection)->statement(
            str_replace('{table}', $temp, $templateSql)
        );

        // Adaptive chunk size: ~500 KB per chunk
        $avgLength = (int) DB::connection($this->connection)
            ->table(self::TABLE)
            ->avg(DB::raw('LENGTH(search_text)'));
        $chunkSize = max(100, min(2000, (int) (500000 / max($avgLength, 1))));

        $stopwordsLookup = array_flip($this->loadStopwords());

        DB::connection($this->connection)
            ->table(self::TABLE)
            ->chunkById($chunkSize, function ($rows) use ($stopwordsLookup, $temp) {
                $batch = [];

                foreach ($rows as $row) {
                    $text = trim($row->search_text ?? '');
                    if ($text === '') {
                        continue;
                    }

                    $words = $this->splitAndCleanText($text);

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
                    // Bulk INSERT with ON DUPLICATE KEY UPDATE
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

        // Atomic swap: RENAME is guaranteed atomic by MySQL
        DB::connection($this->connection)->statement("
            RENAME TABLE
                " . self::VOCAB_TABLE . " TO {$old},
                {$temp} TO " . self::VOCAB_TABLE . "
        ");

        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$old}");
    }
    private function loadStopwords(): array
    {
        $languages = config('illumi-search.stopwords', []);

        if (! is_array($languages) || empty($languages)) {
            return [];
        }

        $filter = new \Moaines\IllumiSearch\Stopwords\StopwordFilter;
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
     * Build the full-text searchable text from a model's document columns.
     *
     * Reads weight config from the model's $searchable and repeats each column's
     * value by its weight (×1-×3) to simulate FTS5 per-column weights in MySQL
     * FULLTEXT, which treats all text as a single field.
     *
     * @param array<string, string> $document
     */
    private function buildSearchText(string $modelClass, array $document): string
    {
        if (! isset(self::$checkedSearchable[$modelClass])) {
            self::$checkedSearchable[$modelClass] = method_exists($modelClass, 'getSearchableColumns');
        }

        if (self::$checkedSearchable[$modelClass]) {
            $searchable = (new $modelClass)->getSearchableColumns();
        } else {
            $searchable = [];
        }

        $text = '';

        foreach ($searchable as $key => $config) {
            $col = is_array($config) ? $key : $config;
            $weight = (int) ($config['weight'] ?? 1);
            $weight = max(1, min(3, $weight));

            $val = $document[$col] ?? '';
            if ($val === '') {
                continue;
            }

            $text .= ' ' . implode(' ', array_fill(0, $weight, $val));
        }

        $maxLength = config('illumi-search.mysql.max_search_text_length', 65535);

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return trim($text);
    }

    /** @param class-string[] $classes */
    private function modelTypePlaceholders(array $classes): array
    {
        return [
            implode(',', array_fill(0, count($classes), '?')),
            array_map(fn ($c) => (string) $c, $classes),
        ];
    }

    /**
     * Split text into words, strip leading/trailing punctuation, filter short/empty.
     *
     * @return string[]
     */
    private function splitAndCleanText(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $words = preg_split('/\s+/', trim($text));
        $result = [];

        foreach ($words as $w) {
            $w = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $w);
            if ($w !== '' && mb_strlen($w) >= 2) {
                $result[] = $w;
            }
        }

        return array_unique($result);
    }

    private function normalizeQuery(string $query): string
    {
        if ($this->textProcessor === null) {
            $this->textProcessor = app(TextProcessor::class);
        }

        return $this->textProcessor->process($query);
    }

    private function toBooleanMode(string $query, string $mode): string
    {
        if ($mode === 'raw') {
            return $query;
        }

        preg_match_all('/"[^"]+"|[^\s]+/', $query, $tokenMatches);
        $terms = $tokenMatches[0];
        $parts = [];
        $pendingOperator = '';

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            $upper = strtoupper($term);

            if (in_array($upper, ['AND', 'OR', 'NOT', 'NEAR'], true)) {
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

            $clean = preg_replace('/[^\p{L}\p{N}\*\-]/u', '', $term);

            if ($clean === '') {
                continue;
            }

            if ($mode === 'basic' && ! str_starts_with($clean, '"')) {
                $clean = rtrim($clean, '*') . '*';
            }

            $clean = $pendingOperator . $clean;
            $pendingOperator = '';

            $part = preg_match('/[^a-zA-Z0-9\*\-]+/', $clean) ? "\"{$clean}\"" : $clean;

            $parts[] = $part;
        }

        if ($pendingOperator === '+') {
            $parts[] = '+';
        }

        return implode(' ', $parts);
    }
}

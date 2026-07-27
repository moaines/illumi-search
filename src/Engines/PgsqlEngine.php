<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moaines\IllumiSearch\Concerns\HasMaxDocuments;
use Moaines\IllumiSearch\Concerns\HasOperatorProcessor;
use Moaines\IllumiSearch\Concerns\HasTenant;
use Moaines\IllumiSearch\Concerns\HasWeightedColumns;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;
use Moaines\IllumiSearch\Support\ParsedOperators;
use Moaines\IllumiSearch\Support\SearchCache;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Text\HasDebugCollector;
use Moaines\IllumiSearch\Text\HasScoring;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Text\NullPragma;
use Symfony\Component\String\UnicodeString;

class PgsqlEngine implements Engine
{
    use HasDebugCollector;
    use HasMaxDocuments;
    use HasOperatorProcessor;
    use HasScoring;
    use HasTenant;
    use HasTextHelpers;
    use HasWeightedColumns;
    use NullPragma;

    public const CONNECTION_NAME = 'illumi-search-pgsql';
    private const INDEX_TABLE = 'index';
    private const TABLE = 'index'; // Alias for HasMaxDocuments trait
    private const CONFIG_TABLE = 'config';
    private const VOCAB_TABLE = 'vocab';
    private const VOCAB_TRIGRAM_TABLE = 'vocab_trigrams';
    private const VOCAB_CHUNK_SIZE = 500;
    private const SUGGEST_TRIGRAM_MULTIPLIER = 3;

    private string $connection = self::CONNECTION_NAME;
    private ?string $createdTableName = null;
    private ?SnippetService $snippets = null;
    private IllumiSearchConfig $illumiConfig;
    private SearchCache $searchCache;
    private int $maxWeight;
    private bool $isRebuilding = false;
    private bool $unaccentReady = false;
    private ?array $cachedTsStatRows = null;

    public function __construct(
        ?SnippetService $snippets = null,
        ?IllumiSearchConfig $config = null,
        mixed $operatorProcessor = null,
    ) {
        $this->illumiConfig = $config ?? app(IllumiSearchConfig::class);
        $this->injectOperatorProcessor($operatorProcessor);
        $this->maxWeight = $this->illumiConfig->maxWeight();
        $this->registerConnection();
        $this->snippets = $snippets;
        $this->searchCache = new SearchCache(storage_path('app/illumi-search-pgsql'));
        $this->searchCache->clear();
    }

    public function registerConnection(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $key = 'database.connections.' . self::CONNECTION_NAME;

        config([
            $key => [
                'driver' => 'pgsql',
                'host' => $this->illumiConfig->pgsqlHost(),
                'port' => $this->illumiConfig->pgsqlPort(),
                'database' => $this->illumiConfig->pgsqlDatabase(),
                'username' => $this->illumiConfig->pgsqlUsername(),
                'password' => $this->illumiConfig->pgsqlPassword(),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'options' => extension_loaded('pdo_pgsql') ? [\PDO::ATTR_PERSISTENT => true] : [],
            ],
        ]);

        $this->connection = self::CONNECTION_NAME;
    }

    private function ensureUnaccent(): void
    {
        if ($this->unaccentReady) {
            return;
        }

        $row = DB::connection($this->connection)->selectOne(
            "SELECT installed_version FROM pg_available_extensions WHERE name = 'unaccent'"
        );

        if ($row && $row->installed_version === null) {
            DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        }

        if ($row) {
            try {
                DB::connection($this->connection)->statement("
                    CREATE TEXT SEARCH CONFIGURATION illumi_unaccent (COPY = simple)
                ");
            } catch (\Throwable) {
                // Config already exists — safe to ignore
            }

            try {
                DB::connection($this->connection)->statement("
                    ALTER TEXT SEARCH CONFIGURATION illumi_unaccent
                    ALTER MAPPING FOR hword, hword_part, word, asciiword WITH unaccent, simple
                ");
                $this->unaccentReady = true;
            } catch (\Throwable $e) {
                $check = DB::connection($this->connection)->selectOne(
                    "SELECT cfgname FROM pg_catalog.pg_ts_config WHERE cfgname = 'illumi_unaccent'"
                );
                $this->unaccentReady = $check !== null;
            }
        }
    }

    private function tsConfig(): string
    {
        if (! $this->unaccentReady) {
            $this->ensureUnaccent();
        }

        return $this->unaccentReady ? 'illumi_unaccent' : 'simple';
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $currentTable = $this->table(self::INDEX_TABLE);
        if ($currentTable === $this->createdTableName) {
            return;
        }

        $this->ensureUnaccent();
        $config = $this->tsConfig();

        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS ' . $currentTable);

        $weightCols = '';
        $vectorParts = [];
        $weightNames = ['A', 'B', 'C', 'D'];

        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $col = "text_w{$w}";
            $weightName = $weightNames[$this->maxWeight - $w] ?? 'D';
            $weightCols .= ", {$col} TEXT NOT NULL DEFAULT ''";
            $vectorParts[] = "setweight(to_tsvector('{$config}', COALESCE({$col}, '')), '{$weightName}')";
        }

        $vectorExpr = implode(' || ', $vectorParts);

        DB::connection($this->connection)->statement("
            CREATE TABLE {$currentTable} (
                id BIGSERIAL NOT NULL,
                model_type VARCHAR(255) NOT NULL,
                model_id VARCHAR(255) NOT NULL,
                last_synced_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP
                {$weightCols} ,
                search_vector TSVECTOR GENERATED ALWAYS AS ({$vectorExpr}) STORED,
                PRIMARY KEY (id),
                UNIQUE (model_type, model_id)
            )
        ");

        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_search_gin ON ' . $currentTable . ' USING GIN (search_vector)'
        );

        // Config table
        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::CONFIG_TABLE) . ' (
                key VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        // Vocab table (for PHP suggest)
        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::VOCAB_TABLE) . ' (
                word VARCHAR(255) NOT NULL UNIQUE,
                doc_count INT NOT NULL DEFAULT 0
            )
        ');

        // Trigram table (for PHP suggest — server-side trigram matching)
        DB::connection($this->connection)->statement('
            CREATE TABLE IF NOT EXISTS ' . $this->table(self::VOCAB_TRIGRAM_TABLE) . ' (
                trigram VARCHAR(3) NOT NULL,
                word VARCHAR(255) NOT NULL,
                doc_count INT NOT NULL DEFAULT 0,
                PRIMARY KEY (trigram, word)
            )
        ');

        $this->createdTableName = $currentTable;
    }

    public function tableName(string $modelClass): string
    {
        return $this->table(self::INDEX_TABLE);
    }

    /** @return array{tableName: string, table: string, modelClass: string} */
    public function table(string $name): string
    {
        $prefix = $this->illumiConfig->tablePrefix();
        $tenantId = $this->tenantId();
        $prefixed = $prefix . ltrim($name, '_');

        return $tenantId !== null ? "{$tenantId}_{$prefixed}" : $prefixed;
    }

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $weightTexts = $this->buildWeightTexts($modelClass, $document);

        DB::connection($this->connection)->statement(
            'INSERT INTO ' . $this->table(self::INDEX_TABLE) . '
             (model_type, model_id, last_synced_at' . $this->weightColumnList() . ')
             VALUES (?, ?, NOW(), ' . $this->weightPlaceholders() . ')
             ON CONFLICT (model_type, model_id) DO UPDATE SET
             last_synced_at = NOW()' . $this->weightUpdateSet(),
            array_merge([$modelClass, $modelId], array_values($weightTexts))
        );
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        DB::connection($this->connection)->statement(
            'DELETE FROM ' . $this->table(self::INDEX_TABLE) . ' WHERE model_type = ? AND model_id = ?',
            [$modelClass, $modelId]
        );
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $table = $this->table(self::INDEX_TABLE);
        $weightCols = $this->weightColumnList();
        $singlePlaceholders = $this->weightPlaceholders();
        $rowPlaceholders = array_fill(0, count($documents), "(?, ?, NOW(), {$singlePlaceholders})");
        $valuePlaceholders = implode(', ', $rowPlaceholders);

        $params = [];
        foreach ($documents as $doc) {
            $weightTexts = $this->buildWeightTexts($modelClass, $doc['document'] ?? []);
            $params[] = $modelClass;
            $params[] = (string) ($doc['model_id'] ?? '');
            foreach ($weightTexts as $val) {
                $params[] = $val;
            }
        }

        DB::connection($this->connection)->statement(
            "INSERT INTO {$table} (model_type, model_id, last_synced_at{$weightCols})
             VALUES {$valuePlaceholders}
             ON CONFLICT (model_type, model_id) DO UPDATE SET
             last_synced_at = NOW(){$this->weightUpdateSet()}",
            $params
        );

        if (! ($this->isRebuilding ?? false)) {
            $this->pruneExcessDocuments($modelClass);
        }
    }

    public function search(
        string $query,
        array $modelClasses,
        int $limit,
        int $offset = 0,
        string $mode = 'advanced',
        bool $withSnippets = true
    ): array {
        $cacheKey = $this->searchCache->key($query . $this->connection . ($this->tenantId() ?? '') . ($withSnippets ? '1' : '0'), $modelClasses, $limit, $offset, $mode);
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

        $safeQuery = $this->normalizeQuery($query);
        $searchStart = microtime(true);
        $pgsqlQuery = $safeQuery; // initialized for use in recordSearchQuery even on cache hit

        if (! $searchDone) {
            // websearch_to_tsquery: AND → implicit, NOT → -, OR → OR, phrase → "", NEAR → AND+PHP
            // Convert operators before passing to PostgreSQL
            $pgsqlQuery = preg_replace('/\bAND\b/ui', '', $pgsqlQuery);    // AND → space (implicit)
            $pgsqlQuery = preg_replace('/\bNOT\b/ui', '-', $pgsqlQuery);    // NOT → -
            $pgsqlQuery = preg_replace('/\bNEAR\b/ui', '', $pgsqlQuery);   // NEAR → space (AND + PHP filter)
            $pgsqlQuery = preg_replace('/\s+/u', ' ', $pgsqlQuery);          // normalize spaces

            // In basic mode, each unquoted word gets :* prefix suffix for PostgreSQL
            if ($mode === 'basic') {
                $pgsqlQuery = preg_replace_callback('/"[^"]+"|[^\s]+/u', function ($m) {
                    $t = $m[0];
                    if (str_starts_with($t, '"')) {
                        return $t; // preserve phrase as-is
                    }
                    return rtrim($t, '*') . ':*';
                }, $pgsqlQuery);
            }

            // Detect prefix wildcard queries like "prog*" or "prog:*"
            $hasPrefix = str_contains($pgsqlQuery, ':*') || preg_match('/\w\*/u', $pgsqlQuery);

            [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelClasses);
            $weightNames = $this->weightColumnNames();

            $config = $this->tsConfig();

            // Compute tsquery via CTE so it's referenced once (not duplicated in SELECT + WHERE)
            // This avoids duplicating bind parameters.
            if ($hasPrefix) {
                [$tsqueryExpr, $tsqueryBindings] = $this->buildPrefixQuery($pgsqlQuery, $config);
            } else {
                $tsqueryExpr = "websearch_to_tsquery('{$config}', ?)";
                $tsqueryBindings = [$pgsqlQuery];
            }

            try {
                DB::connection($this->connection)->statement(
                    "SET statement_timeout = '{$this->illumiConfig->searchTimeoutMs()}ms'"
                );
            } catch (\Throwable) {
                // Not all PostgreSQL versions/configs allow SET statement_timeout
            }

            $rows = DB::connection($this->connection)->select("
                WITH tsq AS (SELECT {$tsqueryExpr} AS q)
                SELECT model_type, model_id,
                       ts_rank(search_vector, tsq.q) AS rank,
                       CONCAT_WS(' ', {$weightNames}) AS search_text,
                       text_w{$this->maxWeight} AS search_title,
                       COUNT(*) OVER () AS total_count
                FROM " . $this->table(self::INDEX_TABLE) . ", tsq
                WHERE model_type IN ({$inPlaceholders})
                  AND search_vector @@ tsq.q
                ORDER BY rank DESC
                LIMIT ? OFFSET ?
            ", array_merge($tsqueryBindings, $inParams, [$limit, $offset]));

            $results = [];
            foreach ($rows as $row) {
                $score = $this->normalizeScore((float) $row->rank, null, $this->maxWeight);
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

            $results = $this->nearFilterResults($results, $safeQuery);

            $this->searchCache->set($rawKey, $results);
        }

        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $safeQuery);
            $this->searchCache->set($enrichedKey, $results);
        }

        $this->setCollectorEngineInfo([
            'version' => $this->getEngineVersion(),
            'driver' => 'pgsql',
        ]);

        $this->recordSearchQuery(
            matchQuery: $pgsqlQuery ?? $safeQuery,
            table: $this->table(self::INDEX_TABLE),
            modelClass: implode(', ', $modelClasses),
            mode: $mode,
            resultCount: count($results),
            durationMs: round((microtime(true) - $searchStart) * 1000, 2),
        );

        return array_map(fn ($r) => Result::fromRaw($r), $results);
    }

    public function count(string $query, array $modelClasses): int
    {
        $safeQuery = $this->normalizeQuery($query);
        $config = $this->tsConfig();
        [$inPlaceholders, $inParams] = $this->modelTypePlaceholders($modelClasses);

        $row = DB::connection($this->connection)->selectOne("
            WITH tsq AS (SELECT plainto_tsquery('{$config}', ?) AS q)
            SELECT COUNT(*) AS total
            FROM " . $this->table(self::INDEX_TABLE) . ", tsq
            WHERE model_type IN ({$inPlaceholders})
              AND search_vector @@ tsq.q
        ", array_merge([$safeQuery], $inParams));

        return (int) ($row->total ?? 0);
    }

    public function getDatabasePath(): string
    {
        return 'pgsql://' . $this->illumiConfig->pgsqlHost() . ':' . $this->illumiConfig->pgsqlPort() . '/' . $this->illumiConfig->pgsqlDatabase();
    }

    public function getDatabaseSize(): ?int
    {
        $row = DB::connection($this->connection)->selectOne("
            SELECT pg_database_size(current_database()) AS size
        ");

        return $row ? (int) $row->size : null;
    }

    public function getEngineVersion(): string
    {
        $row = DB::connection($this->connection)->selectOne('SELECT version() AS v');

        return $row->v ?? 'PostgreSQL';
    }

    public function getEngineStatus(): array
    {
        return [
            'driver' => 'pgsql',
            'connection' => $this->connection,
            'version' => $this->getEngineVersion(),
        ];
    }

    public function getIndexStats(): array
    {
        $table = $this->table(self::INDEX_TABLE);
        $rows = DB::connection($this->connection)->select("
            SELECT model_type, COUNT(*) AS record_count, MAX(last_synced_at) AS last_synced_at
            FROM {$table}
            GROUP BY model_type
        ");

        return array_map(fn ($r) => [
            'model_class' => $r->model_type,
            'record_count' => (int) $r->record_count,
            'last_synced_at' => $r->last_synced_at,
        ], $rows);
    }

    public function getIndexedModelClasses(): array
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT DISTINCT model_type FROM ' . $this->table(self::INDEX_TABLE)
        );

        return array_map(fn ($r) => $r->model_type, $rows);
    }

    public function listIndexTables(): array
    {
        $prefix = $this->illumiConfig->tablePrefix();
        $rows = DB::connection($this->connection)->select("
            SELECT tablename FROM pg_catalog.pg_tables
            WHERE tablename LIKE '{$prefix}%'
        ");

        $tables = array_map(fn ($r) => $r->tablename, $rows);

        return collect($tables)
            ->filter(fn ($t) => ! str_ends_with($t, '_vocab')
                && ! str_ends_with($t, '_config')
                && ! str_ends_with($t, '_vocab_trigrams'))
            ->values()
            ->all();
    }

    public function dropTable(string $modelClass): void
    {
        // PgsqlEngine shares a single index table across all models.
        // Only DELETE rows for this model, don't DROP the shared table.
        DB::connection($this->connection)->statement(
            'DELETE FROM ' . $this->table(self::INDEX_TABLE) . ' WHERE model_type = ?',
            [$modelClass]
        );
        DB::connection($this->connection)->statement(
            'DELETE FROM ' . $this->table(self::CONFIG_TABLE) . " WHERE \"key\" LIKE ?",
            [$modelClass . '%']
        );
    }

    public function dropIndexTable(string $input): void
    {
        $prefix = $this->illumiConfig->tablePrefix();
        $table = \Illuminate\Support\Str::startsWith($input, $prefix) ? $input : $this->tableName($input);
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$table}");
    }

    public function setConfig(string $key, mixed $value): void
    {
        $encoded = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;
        DB::connection($this->connection)->statement(
            'INSERT INTO ' . $this->table(self::CONFIG_TABLE) . ' (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
            [$key, $encoded]
        );
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $row = DB::connection($this->connection)->selectOne(
            'SELECT value FROM ' . $this->table(self::CONFIG_TABLE) . ' WHERE key = ?',
            [$key]
        );

        if ($row === null) {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return $decoded !== null ? $decoded : $row->value;
    }

    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);
        $rows = DB::connection($this->connection)->select(
            'SELECT word, doc_count FROM ' . $this->table(self::VOCAB_TABLE) . '
             WHERE word LIKE ? ORDER BY doc_count DESC LIMIT ?',
            [$escaped . '%', $limit]
        );

        return array_map(fn ($r) => $r->word, $rows);
    }

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }

        $vocabTable = $this->table(self::VOCAB_TABLE);
        $trigramTable = $this->table(self::VOCAB_TRIGRAM_TABLE);

        // On-demand vocab build: if the vocab table is empty (no incremental maintenance),
        // populate it once via ts_stat so all subsequent suggest calls use fast indexed queries.
        $vocabEmpty = DB::connection($this->connection)->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$vocabTable}"
        );
        if ((int) ($vocabEmpty->cnt ?? 0) === 0) {
            try {
                $this->rebuildVocabFromScratch();
            } catch (\Throwable) {
                return [];
            }
        }

        $queryAscii = (new UnicodeString($query))->ascii();
        $queryScripts = $this->scriptsOf($query);
        $queryTrigrams = $this->wordToTrigrams($queryAscii);

        // Phase 1: Trigram matching (server-side, GROUP BY word)
        if (count($queryTrigrams) >= 2) {
            $inPlaceholders = implode(', ', array_fill(0, count($queryTrigrams), '?'));
            $rows = DB::connection($this->connection)->select(
                "SELECT v.word, v.doc_count
                 FROM {$vocabTable} v
                 JOIN {$trigramTable} t ON t.word = v.word
                 WHERE t.trigram IN ({$inPlaceholders})
                 GROUP BY v.word, v.doc_count
                 HAVING COUNT(*) >= 2
                 ORDER BY v.doc_count DESC
                 LIMIT ?",
                [...$queryTrigrams, $limit * self::SUGGEST_TRIGRAM_MULTIPLIER]
            );
            $suggestions = $this->rankSuggestions($rows, $queryAscii, $queryScripts, $maxDistance);
            if (count($suggestions) >= $limit) {
                return array_slice($suggestions, 0, $limit);
            }
        }

        // Phase 2: Prefix + PHP levenshtein fallback
        $prefix = mb_substr($queryAscii, 0, 2);
        if ($prefix !== '') {
            $rows = DB::connection($this->connection)->select(
                "SELECT word, doc_count
                 FROM {$vocabTable}
                 WHERE LOWER(SUBSTRING(word FROM 1 FOR 2)) = LOWER(?)
                 ORDER BY doc_count DESC
                 LIMIT ?",
                [$prefix, $this->illumiConfig->sqliteVocabLimit()]
            );
            $suggestions = array_merge($suggestions, $this->rankSuggestions($rows, $queryAscii, $queryScripts, $maxDistance));
        }

        // Phase 3: ts_stat fallback (when vocab is empty)
        if (empty($suggestions)) {
            // Use cached ts_stat results if available (avoids re-scanning the GIN index)
            if ($this->cachedTsStatRows === null) {
                $table = $this->table(self::INDEX_TABLE);
                $check = DB::connection($this->connection)->selectOne(
                    'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = ?', [$table]
                );
                if ($check) {
                    try {
                        $this->cachedTsStatRows = DB::connection($this->connection)->select(
                            "SELECT word, ndoc AS doc_count
                             FROM ts_stat('SELECT search_vector FROM {$table}')
                             ORDER BY ndoc DESC"
                        );
                    } catch (\Throwable) {
                        $this->cachedTsStatRows = [];
                    }
                } else {
                    $this->cachedTsStatRows = [];
                }
            }
            $suggestions = $this->rankSuggestions($this->cachedTsStatRows, $queryAscii, $queryScripts, $maxDistance);
        }

        $suggestions = collect($suggestions)
            ->sortBy('score')
            ->pluck('word')
            ->take($limit)
            ->values()
            ->all();

        return $suggestions;
    }

    private function rankSuggestions(array $rows, string $queryAscii, array $queryScripts, int $maxDistance): array
    {
        $results = [];
        foreach ($rows as $row) {
            $word = $row->word ?? '';
            if (mb_strlen($word) < 2) continue;
            $wordAscii = (string) (new UnicodeString($word))->ascii();
            $d = levenshtein($queryAscii, $wordAscii);
            if ($d !== -1 && $d <= $maxDistance) {
                $wordScripts = $this->scriptsOf($word);
                $penalty = empty(array_intersect($queryScripts, $wordScripts)) ? 3 : 0;
                $results[] = ['word' => $word, 'score' => $d + $penalty];
            }
        }

        usort($results, fn ($a, $b) => $a['score'] <=> $b['score']);

        return $results;
    }

    private function buildPrefixQuery(string $query, string $config): array
    {
        // Convert "prog*" or "prog:*" → to_tsquery('simple', 'prog:*')
        $terms = preg_split('/\s+/u', trim($query));
        $tsqParts = [];
        $bindings = [];
        foreach ($terms as $term) {
            if (str_ends_with($term, ':*') || str_ends_with($term, '*')) {
                $clean = rtrim(rtrim($term, '*'), ':');
                $tsqParts[] = "to_tsquery('{$config}', ?)";
                $bindings[] = $clean . ':*';
            } elseif (str_starts_with($term, '-')) {
                $tsqParts[] = "!! to_tsquery('{$config}', ?)";
                $bindings[] = ltrim($term, '-');
            } else {
                $tsqParts[] = "to_tsquery('{$config}', ?)";
                $bindings[] = $term;
            }
        }

        return [implode(' && ', $tsqParts), $bindings];
    }

    public function supportsPhraseSearch(): bool
    {
        return true;
    }

    public function supportsPrefixWildcard(): bool
    {
        return true;
    }

    public function vacuum(): void
    {
        DB::connection($this->connection)->statement('VACUUM ANALYZE');
    }

    public function fullIntegrityCheck(): array
    {
        return ['passed' => true, 'errors' => []];
    }

    public function dropAllTables(): void
    {
        foreach ($this->listIndexTables() as $table) {
            DB::connection($this->connection)->statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    private function buildWeightTexts(string $modelClass, array $document): array
    {
        $texts = [];
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $texts[$w] = '';
        }

        $processor = app(\Moaines\IllumiSearch\Contracts\TextProcessor::class);

        $colIndex = 0;
        foreach ($document as $value) {
            $colIndex++;
            $weight = max(1, min($this->maxWeight, $this->maxWeight - $colIndex + 1));
            $texts[$weight] .= ' ' . $processor->process((string) $value, 'en');
        }

        foreach ($texts as $w => $t) {
            $texts[$w] = trim($t);
        }

        return $texts;
    }

    private function weightColumnList(): string
    {
        $cols = '';
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $cols .= ", text_w{$w}";
        }

        return $cols;
    }

    private function weightPlaceholders(): string
    {
        return implode(', ', array_fill(0, $this->maxWeight, '?'));
    }

    private function weightUpdateSet(): string
    {
        $set = '';
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $set .= ", text_w{$w} = EXCLUDED.text_w{$w}";
        }

        return $set;
    }

    public function setRebuilding(bool $isRebuilding): void
    {
        $this->isRebuilding = $isRebuilding;
    }

    public function optimize(): array
    {
        $this->vacuum();

        return ['status' => 'ok'];
    }

    public function tableExists(string $modelClass): bool
    {
        $row = DB::connection($this->connection)->selectOne(
            'SELECT COUNT(*) AS cnt FROM pg_catalog.pg_tables WHERE tablename = ?',
            [$this->tableName($modelClass)]
        );

        return ($row->cnt ?? 0) > 0;
    }

    public function integrityCheck(string $modelClass): bool
    {
        return $this->tableExists($modelClass);
    }

    public function rebuildVocabFromScratch(): void
    {
        $table = $this->table(self::INDEX_TABLE);
        $vocabTable = $this->table(self::VOCAB_TABLE);

        // Reset cached ts_stat so next suggest gets fresh data
        $this->cachedTsStatRows = null;

        // Check that tables exist before operating on them
        $indexTableExists = DB::connection($this->connection)->selectOne(
            'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = ?', [$table]
        );
        $vocabTableExists = DB::connection($this->connection)->selectOne(
            'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = ?', [$vocabTable]
        );

        if (! $indexTableExists || ! $vocabTableExists) {
            return;
        }

        DB::connection($this->connection)->statement("TRUNCATE {$vocabTable}");

        try {
            $rows = DB::connection($this->connection)->select(
                "SELECT word, ndoc AS doc_count FROM ts_stat('SELECT search_vector FROM {$table}')"
            );
            $this->cachedTsStatRows = $rows;
        } catch (\Throwable) {
            $rows = [];
        }

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, self::VOCAB_CHUNK_SIZE) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $word = trim($row->word ?? '');
                if (mb_strlen($word) < 2) {
                    continue;
                }
                $values[] = '(?, ?)';
                $params[] = $word;
                $params[] = (int) ($row->doc_count ?? 0);
            }
            if (! empty($values)) {
                DB::connection($this->connection)->statement(
                    "INSERT INTO {$vocabTable} (word, doc_count) VALUES " . implode(', ', $values) . "
                     ON CONFLICT (word) DO UPDATE SET doc_count = EXCLUDED.doc_count",
                    $params
                );
            }
        }

        $this->rebuildTrigramTable();

        // Warmup suggest cache for top common words — prevents cold ts_stat scans
        // on the first suggest calls after rebuild.
        try {
            $topWords = DB::connection($this->connection)->select(
                'SELECT word FROM ' . $this->table(self::VOCAB_TABLE) . ' ORDER BY doc_count DESC LIMIT 5'
            );
            foreach ($topWords as $w) {
                $this->suggest(mb_substr($w->word ?? '', 0, 5), 2, 3);
            }
        } catch (\Throwable) {
            // Warmup is best-effort — not critical for correctness
        }
    }

    public function rebuildTrigramTable(): void
    {
        $vocabTable = $this->table(self::VOCAB_TABLE);
        $trigramTable = $this->table(self::VOCAB_TRIGRAM_TABLE);

        // Check that the trigram table exists
        $tableExists = DB::connection($this->connection)->selectOne(
            'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = ?', [$trigramTable]
        );
        if (!$tableExists) {
            return;
        }

        DB::connection($this->connection)->statement("TRUNCATE {$trigramTable}");

        $rows = DB::connection($this->connection)->select(
            "SELECT word FROM {$vocabTable}"
        );

        foreach (array_chunk($rows, self::VOCAB_CHUNK_SIZE) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $word = $row->word ?? '';
                if (mb_strlen($word) < 2) continue;
                $ascii = (string) (new UnicodeString($word))->ascii();
                $trigrams = $this->wordToTrigrams($ascii);
                foreach ($trigrams as $trigram) {
                    $values[] = '(?, ?, ?)';
                    $params[] = $trigram;
                    $params[] = $word;
                    $params[] = 1;
                }
            }
            if (! empty($values)) {
                DB::connection($this->connection)->statement(
                    "INSERT INTO {$trigramTable} (trigram, word, doc_count) VALUES " . implode(', ', $values) . "
                     ON CONFLICT (trigram, word) DO UPDATE SET doc_count = {$trigramTable}.doc_count + 1",
                    $params
                );
            }
        }
    }

    public function getSupportedOperators(): array
    {
        return ['AND', 'OR', 'NOT', 'NEAR'];
    }

    public function isFts5Available(): bool
    {
        return false;
    }
}

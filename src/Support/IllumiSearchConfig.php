<?php

namespace Moaines\IllumiSearch\Support;

/**
 * Centralized access to illumi-search configuration.
 *
 * Avoids scattering `config('illumi-search.*')` calls across engines,
 * making the configuration injectable and mockable in tests.
 *
 * Usage:
 *   $config = app(IllumiSearchConfig::class);
 *   $driver = $config->driver();
 */
class IllumiSearchConfig
{
    private ?string $driver = null;
    private ?int $maxWeight = null;
    private ?string $tablePrefix = null;
    private ?int $workers = null;
    private ?string $processor = null;
    private ?array $stopwords = null;

    // ─── Engine selection ───────────────────────────────

    public function driver(): string
    {
        return $this->driver ??= config('illumi-search.driver', 'sqlite');
    }

    public function maxWeight(): int
    {
        return $this->maxWeight ??= (int) config('illumi-search.processing.max_weight', 3);
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix ??= config('illumi-search.processing.table_prefix', 'illumi_search_');
    }

    public function workers(): int
    {
        return $this->workers ??= (int) config('illumi-search.workers', 4);
    }

    public function processor(): string
    {
        return $this->processor ??= config('illumi-search.processing.processor', 'unicode');
    }

    public function stopwords(): array
    {
        if ($this->stopwords === null) {
            $sw = config('illumi-search.processing.stopwords', ['en']);
            $this->stopwords = is_array($sw) ? $sw : ['en'];
        }

        return $this->stopwords;
    }

    // ─── SQLite-specific ────────────────────────────────

    public function sqliteDatabasePath(): string
    {
        return config('illumi-search.engines.sqlite.database_path', 'app/search/search-index.sqlite');
    }

    public function sqliteTokenizer(): string
    {
        return config('illumi-search.engines.sqlite.fts5.tokenizer', 'unicode61');
    }

    public function sqlitePrefixLengths(): array
    {
        return config('illumi-search.engines.sqlite.fts5.prefix_lengths', [2, 3, 4]);
    }

    public function sqliteDetail(): string
    {
        return config('illumi-search.engines.sqlite.fts5.detail', 'full');
    }

    public function sqliteColumnsize(): int
    {
        return (int) config('illumi-search.engines.sqlite.fts5.columnsize', 1);
    }

    public function sqliteAutomerge(): int
    {
        return (int) config('illumi-search.engines.sqlite.fts5.automerge', 4);
    }

    public function sqliteCrisismerge(): int
    {
        return (int) config('illumi-search.engines.sqlite.fts5.crisismerge', 16);
    }

    public function sqlitePgsz(): int
    {
        return (int) config('illumi-search.engines.sqlite.fts5.pgsz', 1000);
    }

    public function sqliteVocabLimit(): int
    {
        return (int) config('illumi-search.spellcheck.vocab_limit', 5000);
    }

    /**
     * @return string[]|null
     */
    public function operators(): ?array
    {
        $allowed = config('illumi-search.operators.enabled');

        if (is_string($allowed)) {
            return array_map('trim', explode(',', $allowed));
        }

        return $allowed; // null or array
    }

    public function sqliteWal(): bool
    {
        return filter_var(config('illumi-search.engines.sqlite.runtime.wal', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function sqliteSynchronous(): string
    {
        return config('illumi-search.engines.sqlite.runtime.synchronous', 'NORMAL');
    }

    public function sqliteCacheSizeKb(): int
    {
        return (int) config('illumi-search.engines.sqlite.runtime.cache_size_kb', -64000);
    }

    public function sqliteTempStore(): string
    {
        return config('illumi-search.engines.sqlite.runtime.temp_store', 'MEMORY');
    }

    public function sqliteBusyTimeout(): int
    {
        return (int) config('illumi-search.engines.sqlite.runtime.busy_timeout', 15000);
    }

    public function sqliteMmapSize(): int
    {
        return (int) config('illumi-search.engines.sqlite.runtime.mmap_size', 0);
    }

    // ─── MySQL-specific ─────────────────────────────────

    public function mysqlHost(): string
    {
        return config('illumi-search.engines.mysql.connection.host', '127.0.0.1');
    }

    public function mysqlPort(): string
    {
        return config('illumi-search.engines.mysql.connection.port', '3306');
    }

    public function mysqlDatabase(): string
    {
        return config('illumi-search.engines.mysql.connection.database', 'illumi_search');
    }

    public function mysqlUsername(): string
    {
        return config('illumi-search.engines.mysql.connection.username', 'root');
    }

    public function mysqlPassword(): string
    {
        return config('illumi-search.engines.mysql.connection.password', '');
    }

    public function mysqlUnixSocket(): string
    {
        return config('illumi-search.engines.mysql.connection.unix_socket', '');
    }

    // ─── Search behaviour ────────────────────────────────

    public function processingMode(): string
    {
        return $this->processingMode ??= config('illumi-search.processing.mode', 'advanced');
    }

    public function maxResults(): int
    {
        return $this->maxResults ??= (int) config('illumi-search.processing.max_results', 50);
    }

    public function maxRelatedValues(): int
    {
        return $this->maxRelatedValues ??= (int) config('illumi-search.processing.max_related_values', 100);
    }

    public function maxSearchTextLength(): int
    {
        return $this->maxSearchTextLength ??= (int) config('illumi-search.processing.max_search_text_length', 65535);
    }

    // ─── Indexing ────────────────────────────────────────

    public function indexingMode(): string
    {
        return $this->indexingMode ??= config('illumi-search.indexing.mode', 'queue');
    }

    public function rebuildBatchSize(): int
    {
        return (int) config('illumi-search.indexing.rebuild_batch_size', 0);
    }

    // ─── Multi-tenancy ───────────────────────────────────

    public function tenancyEnabled(): bool
    {
        return (bool) config('illumi-search.tenancy.enabled', false);
    }

    public function tenancyDirectory(): string
    {
        return config('illumi-search.tenancy.directory', 'app/search/tenants');
    }

    // ─── Model discovery ─────────────────────────────────

    public function modelPaths(): array
    {
        return config('illumi-search.model_paths', [app_path('Models')]);
    }

    // ─── Queue ──────────────────────────────────────────

    public function queueConnection(): ?string
    {
        return config('illumi-search.queue_connection');
    }

    // ─── Authorization ──────────────────────────────────

    public function authorizationEnabled(): bool
    {
        return (bool) config('illumi-search.authorization.enabled', false);
    }

    private ?string $processingMode = null;
    private ?int $maxResults = null;
    private ?int $maxRelatedValues = null;
    private ?int $maxSearchTextLength = null;
    private ?string $indexingMode = null;
}

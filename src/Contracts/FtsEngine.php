<?php

namespace Moaines\LaravelFts\Contracts;

use Moaines\LaravelFts\FtsResult;

interface FtsEngine
{
    /**
     * Create an FTS5 virtual table for a model class.
     *
     * @example $engine->createTable(Post::class, ['title', 'body'], [2, 3, 4])
     */
    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void;

    /**
     * Drop an FTS5 virtual table and its vocab table.
     *
     * @example $engine->dropTable(Post::class)
     */
    public function dropTable(string $modelClass): void;

    /**
     * Insert or replace a single document in the FTS index.
     *
     * @example $engine->upsert(Post::class, 1, ['title' => 'Hello', 'body' => 'World'])
     */
    public function upsert(string $modelClass, int|string $modelId, array $document): void;

    /**
     * Remove a document from the FTS index.
     *
     * @example $engine->delete(Post::class, 1)
     */
    public function delete(string $modelClass, int|string $modelId): void;

    /**
     * Insert multiple documents in a single transaction.
     *
     * @example $engine->insertBatch(Post::class, [['model_id' => 1, 'document' => [...]], ...])
     */
    public function insertBatch(string $modelClass, array $documents): void;

    /**
     * Search the FTS index and return ranked results.
     *
     * @example $results = $engine->search('laravel', [Post::class], 10)
     *
     * @return FtsResult[]
     */
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array;

    public function count(string $query, array $modelClasses): int;

    public function tableExists(string $modelClass): bool;

    public function integrityCheck(string $modelClass): bool;

    public function tableName(string $modelClass): string;

    /** @return array<string> */
    public function listIndexTables(): array;

    public function dropIndexTable(string $tableName): void;

    public function getIndexedModelClasses(): array;

    public function getIndexStats(): array;

    public function vacuum(): void;

    public function optimize(): array;

    public function getDatabasePath(): string;

    public function getDatabaseSize(): int;

    /** @return array<int, array{term: string, cnt: int}> */
    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array;

    /** Get SQLite and FTS5 version string. */
    public function getEngineVersion(): string;

    /**
     * Query a read-only PRAGMA.
     * Supported: journal_mode, synchronous, cache_size, temp_store,
     * busy_timeout, mmap_size, wal_autocheckpoint, page_size, page_count,
     * freelist_count, application_id, user_version.
     */
    public function getPragma(string $name): string|int|null;

    /** Run integrity_check on all FTS5 tables. Returns ['passed' => bool, 'errors' => list<string>]. */
    public function fullIntegrityCheck(): array;

    /** Read a value from the config storage table. */
    public function getConfig(string $key, mixed $default = null): mixed;

    /** Write a value to the config storage table. */
    public function setConfig(string $key, mixed $value): void;
}

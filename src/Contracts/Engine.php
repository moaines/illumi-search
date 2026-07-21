<?php

namespace Moaines\IllumiSearch\Contracts;

use Moaines\IllumiSearch\Result;

interface Engine
{
    /** Standard Boolean operators supported across all engines. */
    public const OPERATORS = ['AND', 'OR', 'NOT', 'NEAR'];
    /**
     * Insert or replace a single document in the FTS index.
     *
     * @param array<string, string> $document
     */
    public function upsert(string $modelClass, int|string $modelId, array $document): void;

    /**
     * Remove a document from the FTS index.
     */
    public function delete(string $modelClass, int|string $modelId): void;

    /**
     * Insert multiple documents in a single transaction.
     *
     * @param array<int, array{model_id: int|string, document: array<string, string>}> $documents
     */
    public function insertBatch(string $modelClass, array $documents): void;

    /**
     * Search the FTS index and return ranked results.
     *
     * @param array<class-string> $modelClasses
     * @return Result[]
     */
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array;

    /** @param array<class-string> $modelClasses */
    public function count(string $query, array $modelClasses): int;

    /** @return array<class-string> */
    public function getIndexedModelClasses(): array;

    /** @return array<int, array{model_class: string, record_count: int, last_synced_at: ?string, columns: ?string}> */
    public function getIndexStats(): array;

    /** @return array{vacuum: array{before: int, after: int}, tables_optimized: int} */
    public function optimize(): array;

    /** Get engine version string. */
    public function getEngineVersion(): string;

    /** Read a value from the config storage table. */
    public function getConfig(string $key, mixed $default = null): mixed;

    /** Write a value to the config storage table. */
    public function setConfig(string $key, mixed $value): void;

    /**
     * Create an FTS index table for a model class.
     *
     * @param string[] $columns
     * @param int[] $prefixLengths
     */
    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void;

    /** Drop an FTS index table for a model class. */
    public function dropTable(string $modelClass): void;

    /** Drop the underlying index table only (keep meta). */
    public function dropIndexTable(string $modelClass): void;

    /** Get the internal FTS table name for a model class. */
    public function tableName(string $modelClass): string;

    /** Check if an FTS table exists for a model class. */
    public function tableExists(string $modelClass): bool;

    /**
     * List all FTS tables in the index database.
     *
     * @return string[]
     */
    public function listIndexTables(): array;

    /** Run VACUUM on the database to reclaim space. */
    public function vacuum(): void;

    /** Get the filesystem path to the database file. */
    public function getDatabasePath(): string;

    /** Get the database file size in bytes, or null if not accessible. */
    public function getDatabaseSize(): ?int;

    /** Run an integrity check on a specific model's FTS table. */
    public function integrityCheck(string $modelClass): bool;

    /**
     * Run a full integrity check, including data consistency.
     *
     * @return array{passed: bool, errors: string[]}
     */
    public function fullIntegrityCheck(): array;

    /**
     * Query the FTS5 vocabulary table for suggestions.
     *
     * @return string[]
     */
    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array;

    /**
     * Suggest spelling corrections for a query term.
     *
     * @return string[]
     */
    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array;

    /** Check if FTS5 is available in the SQLite build. */
    public function isFts5Available(): bool;

    /** Read a PRAGMA value from the connection. */
    public function getPragma(string $name): string|int|null;
}

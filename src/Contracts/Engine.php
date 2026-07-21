<?php

namespace Moaines\IllumiSearch\Contracts;

use Moaines\IllumiSearch\Result;

interface Engine
{
    /**
     * Insert or replace a single document in the FTS index.
     *
     * @example $engine->upsert(Post::class, 1, ['title' => 'Hello', 'body' => 'World'])
     *
     * @param array<string, string> $document
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
     *
     * @param array<int, array{model_id: int|string, document: array<string, string>}> $documents
     */
    public function insertBatch(string $modelClass, array $documents): void;

    /**
     * Search the FTS index and return ranked results.
     *
     * @example $results = $engine->search('laravel', [Post::class], 10)
     *
     * @param array<class-string> $modelClasses
     *
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
}

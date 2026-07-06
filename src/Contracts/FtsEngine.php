<?php

namespace Moaines\LaravelFts\Contracts;

use Moaines\LaravelFts\FtsResult;

interface FtsEngine
{
    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void;

    public function dropTable(string $modelClass): void;

    public function upsert(string $modelClass, int|string $modelId, array $document): void;

    public function delete(string $modelClass, int|string $modelId): void;

    public function insertBatch(string $modelClass, array $documents): void;

    /** @return FtsResult[] */
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced'): array;

    public function count(string $query, array $modelClasses): int;

    public function tableExists(string $modelClass): bool;

    public function getIndexedModelClasses(): array;

    public function getIndexStats(): array;

    public function vacuum(): void;

    public function optimize(): array;

    public function getDatabasePath(): string;

    public function getDatabaseSize(): int;

    /** @return array<int, array{term: string, cnt: int}> */
    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array;
}

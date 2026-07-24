<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\TenantManager;

/**
 * Proxy engine that resolves a fresh engine instance on every call.
 *
 * Used in multi-tenant setups where the underlying engine's database path
 * changes per tenant. The singleton Engine would cache the first tenant's
 * path, causing cross-tenant data leakage.
 *
 * Each method call delegates to a freshly resolved engine instance.
 * Automatically detects tenant changes and refreshes the underlying engine.
 *
 * @internal
 */
class EngineProxy implements Engine
{
    /** @var callable(): Engine */
    private $resolver;

    private ?Engine $currentEngine = null;
    private ?string $currentTenantId = null;

    /**
     * @param  callable(): Engine  $resolver  Factory that returns a fresh Engine
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    private function resolve(): Engine
    {
        $this->detectTenantChange();

        if ($this->currentEngine === null) {
            $this->currentEngine = ($this->resolver)();
        }

        return $this->currentEngine;
    }

    private function detectTenantChange(): void
    {
        if (! class_exists(TenantManager::class)) {
            return;
        }

        $tenantManager = app(TenantManager::class);

        if (! $tenantManager->enabled()) {
            return;
        }

        $currentId = $tenantManager->tenantId() ?? '__default__';

        if ($this->currentTenantId !== null && $this->currentTenantId !== $currentId) {
            $this->currentEngine = null;
        }

        $this->currentTenantId = $currentId;
    }

    public function refresh(): void
    {
        $this->currentEngine = null;
        $this->currentTenantId = null;
    }

    // ─── Engine interface (delegate all to resolve()) ───

    public function setRebuilding(bool $isRebuilding): void
    {
        $this->resolve()->setRebuilding($isRebuilding);
    }

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $this->resolve()->upsert($modelClass, $modelId, $document);
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $this->resolve()->delete($modelClass, $modelId);
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        $this->resolve()->insertBatch($modelClass, $documents);
    }

    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        return $this->resolve()->search($query, $modelClasses, $limit, $offset, $mode, $withSnippets);
    }

    public function count(string $query, array $modelClasses): int
    {
        return $this->resolve()->count($query, $modelClasses);
    }

    public function getIndexedModelClasses(): array
    {
        return $this->resolve()->getIndexedModelClasses();
    }

    public function getIndexStats(): array
    {
        return $this->resolve()->getIndexStats();
    }

    public function optimize(): array
    {
        return $this->resolve()->optimize();
    }

    public function getEngineVersion(): string
    {
        return $this->resolve()->getEngineVersion();
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->resolve()->getConfig($key, $default);
    }

    public function setConfig(string $key, mixed $value): void
    {
        $this->resolve()->setConfig($key, $value);
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $this->resolve()->createTable($modelClass, $columns, $prefixLengths);
    }

    public function dropTable(string $modelClass): void
    {
        $this->resolve()->dropTable($modelClass);
    }

    public function dropIndexTable(string $modelClass): void
    {
        $this->resolve()->dropIndexTable($modelClass);
    }

    public function listIndexTables(): array
    {
        return $this->resolve()->listIndexTables();
    }

    public function tableName(string $modelClass): string
    {
        return $this->resolve()->tableName($modelClass);
    }

    public function tableExists(string $modelClass): bool
    {
        return $this->resolve()->tableExists($modelClass);
    }

    public function vacuum(): void
    {
        $this->resolve()->vacuum();
    }

    public function getDatabasePath(): string
    {
        return $this->resolve()->getDatabasePath();
    }

    public function getDatabaseSize(): ?int
    {
        return $this->resolve()->getDatabaseSize();
    }

    public function integrityCheck(string $modelClass): bool
    {
        return $this->resolve()->integrityCheck($modelClass);
    }

    public function fullIntegrityCheck(): array
    {
        return $this->resolve()->fullIntegrityCheck();
    }

    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        return $this->resolve()->queryVocab($modelClass, $term, $maxDistance, $limit);
    }

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        return $this->resolve()->suggest($query, $maxDistance, $limit);
    }

    public function isFts5Available(): bool
    {
        return $this->resolve()->isFts5Available();
    }

    public function getPragma(string $name): string|int|null
    {
        return $this->resolve()->getPragma($name);
    }

    public function getEngineStatus(): array
    {
        return $this->resolve()->getEngineStatus();
    }

    public function getSupportedOperators(): array
    {
        return $this->resolve()->getSupportedOperators();
    }

    public function supportsPhraseSearch(): bool
    {
        return $this->resolve()->supportsPhraseSearch();
    }

    public function supportsPrefixWildcard(): bool
    {
        return $this->resolve()->supportsPrefixWildcard();
    }

    public function rebuildVocabFromScratch(): void
    {
        $this->resolve()->rebuildVocabFromScratch();
    }
}

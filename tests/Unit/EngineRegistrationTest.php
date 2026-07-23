<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\IllumiSearchServiceProvider;
use Moaines\IllumiSearch\Tests\TestCase;

class EngineRegistrationTest extends TestCase
{
    public function test_custom_engine_can_be_registered_and_resolved(): void
    {
        $called = false;

        IllumiSearchServiceProvider::extend('test_engine', function ($app) use (&$called) {
            $called = true;

            return new class implements Engine
            {
                public function upsert(string $modelClass, int|string $modelId, array $document): void {}
                public function delete(string $modelClass, int|string $modelId): void {}
                public function insertBatch(string $modelClass, array $documents): void {}
                public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array { return []; }
                public function count(string $query, array $modelClasses): int { return 0; }
                public function getIndexedModelClasses(): array { return []; }
                public function getIndexStats(): array { return []; }
                public function optimize(): array { return ['vacuum' => ['before' => 0, 'after' => 0], 'tables_optimized' => 0]; }
                public function getEngineVersion(): string { return 'TestEngine 1.0'; }
                public function getConfig(string $key, mixed $default = null): mixed { return $default; }
                public function setConfig(string $key, mixed $value): void {}
                public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void {}
                public function dropTable(string $modelClass): void {}
                public function dropIndexTable(string $modelClass): void {}
                public function tableName(string $modelClass): string { return 'test'; }
                public function tableExists(string $modelClass): bool { return false; }
                public function listIndexTables(): array { return []; }
                public function vacuum(): void {}
                public function getDatabasePath(): string { return ':memory:'; }
                public function getDatabaseSize(): ?int { return 0; }
                public function integrityCheck(string $modelClass): bool { return true; }
                public function fullIntegrityCheck(): array { return ['passed' => true, 'errors' => []]; }
                public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array { return []; }
                public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array { return []; }
                public function isFts5Available(): bool { return false; }
                public function getPragma(string $name): string|int|null { return null; }
                public function getEngineStatus(): array { return ['driver' => 'TestEngine']; }
                public function getSupportedOperators(): array { return ['AND', 'OR']; }
                public function supportsPhraseSearch(): bool { return true; }
                public function supportsPrefixWildcard(): bool { return true; }
            };
        });

        config(['illumi-search.driver' => 'test_engine']);
        $engine = app(Engine::class);

        $this->assertTrue($called, 'Custom engine resolver should be called');
        $this->assertNotSame('mysql', $engine->getEngineStatus()['driver']);
        $this->assertSame('TestEngine', $engine->getEngineStatus()['driver']);
    }
}

<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;
use PHPUnit\Framework\Attributes\Test;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MeilisearchEngine;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\ChecksMeilisearch;
use Moaines\IllumiSearch\Tests\TestCase;

class MeilisearchEngineIntegrationTest extends TestCase
{
    use ChecksMeilisearch;

    private const MODEL_CLASS = 'App\Models\BenchmarkPost';

    private ?MeilisearchEngine $engine = null;

    protected function createEngine(): Engine
    {
        if (! $this->meilisearchAvailable()) {
            $this->markTestSkipped('Meilisearch is not available');
        }

        if ($this->engine === null) {
            $this->engine = new MeilisearchEngine(
                host: config('illumi-search.engines.meilisearch.host', 'http://localhost:7700'),
                apiKey: config('illumi-search.engines.meilisearch.api_key', 'masterKey'),
            );
        }

        try {
            $this->engine->dropTable(self::MODEL_CLASS);
        } catch (\Exception) {
        }

        $this->engine->createTable(self::MODEL_CLASS, ['title', 'body']);

        return $this->engine;
    }

    protected function tearDown(): void
    {
        if ($this->engine !== null) {
            try {
                $this->engine->dropTable(self::MODEL_CLASS);
            } catch (\Exception) {
            }
        }
        parent::tearDown();
    }

    public function test_engine_status_returns_expected_keys(): void
    {
        $engine = $this->createEngine();
        $status = $engine->getEngineStatus();

        $this->assertArrayHasKey('driver', $status);
        $this->assertArrayHasKey('engine', $status);
        $this->assertArrayHasKey('version', $status);
        $this->assertArrayHasKey('indexes', $status);
        $this->assertArrayHasKey('total_documents', $status);
        $this->assertSame('Meilisearch', $status['driver']);
    }

    public function test_get_database_size_returns_positive_integer(): void
    {
        $engine = $this->createEngine();
        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'data', 'body' => 'content']);

        $size = $engine->getDatabaseSize();
        $this->assertNotNull($size);
        $this->assertGreaterThan(0, $size);
    }

    public function test_suggest_returns_results_for_typo(): void
    {
        $engine = $this->createEngine();
        $engine->upsert(self::MODEL_CLASS, 1, [
            'title' => 'programming',
            'body' => 'learn programming in php',
        ]);

        $suggestions = $engine->suggest('programing', 2, 5);
        $this->assertNotEmpty($suggestions);
        $this->assertContains('programming', $suggestions);
    }

    public function test_table_operations_create_drop_exists(): void
    {
        $engine = $this->createEngine();

        $this->assertTrue($engine->tableExists(self::MODEL_CLASS));

        $tables = $engine->listIndexTables();
        $this->assertNotEmpty($tables);

        $engine->dropTable(self::MODEL_CLASS);
        $this->assertFalse($engine->tableExists(self::MODEL_CLASS));
    }

    public function test_optimize_returns_structure(): void
    {
        $engine = $this->createEngine();
        $result = $engine->optimize();

        $this->assertArrayHasKey('vacuum', $result);
        $this->assertArrayHasKey('tables_optimized', $result);
        $this->assertIsInt($result['tables_optimized']);
    }

    public function test_full_integrity_check(): void
    {
        $engine = $this->createEngine();
        $result = $engine->fullIntegrityCheck();

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsBool($result['passed']);
        $this->assertIsArray($result['errors']);
    }

    public function test_get_supported_operators_returns_list(): void
    {
        $engine = $this->createEngine();
        $ops = $engine->getSupportedOperators();

        $this->assertContains('AND', $ops);
        $this->assertContains('OR', $ops);
        $this->assertContains('NOT', $ops);
        $this->assertContains('NEAR', $ops);
    }

    public function test_get_engine_version_returns_string(): void
    {
        $engine = $this->createEngine();
        $version = $engine->getEngineVersion();

        $this->assertNotEmpty($version);
        $this->assertIsString($version);
    }
}

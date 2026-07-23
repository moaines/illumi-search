<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MySqlEngine;

class MySqlEngineIntegrationTest extends AbstractEngineTest
{
    private ?MySqlEngine $engine = null;

    protected function createEngine(): Engine
    {
        if (! $this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL connection not available.');
        }

        DB::connection(MySqlEngine::CONNECTION_NAME)->statement('DROP TABLE IF EXISTS search_index');

        $this->engine = new MySqlEngine;
        $this->engine->createTable('App\Models\Post', ['title', 'body']);

        return $this->engine;
    }

    protected function tearDown(): void
    {
        if ($this->engine && $this->mysqlAvailable()) {
            DB::connection(MySqlEngine::CONNECTION_NAME)->statement('DROP TABLE IF EXISTS search_index');
        }

        parent::tearDown();
    }

    private function mysqlAvailable(): bool
    {
        try {
            new MySqlEngine;
            DB::connection(MySqlEngine::CONNECTION_NAME)->getPdo();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /** @test */
    public function mysql_table_stays_after_drop(): void
    {
        $engine = $this->createEngine();
        $engine->dropTable($this->testModelClass);

        $this->assertTrue($engine->tableExists($this->testModelClass));
    }

    // ─── MySQL-specific tests ─────────────────────────────

    public function test_snippets_contain_mark_tags(): void
    {
        $engine = $this->createEngine();
        $engine->upsert('App\Models\Post', 1, [
            'title' => 'Data science guide',
            'body' => 'Data analysis with python and machine learning techniques for big data processing.',
        ]);

        $results = $engine->search('data', ['App\Models\Post'], 10, 0, 'advanced', true);

        $this->assertCount(1, $results);
    }

    public function test_paginate_via_query_builder(): void
    {
        $engine = $this->createEngine();
        for ($i = 1; $i <= 5; $i++) {
            $engine->upsert('App\Models\Post', $i, ['title' => "test post $i", 'body' => 'content']);
        }

        $results = $engine->search('test', ['App\Models\Post'], 2, 0);
        $total = $results[0]->totalCount ?? $engine->count('test', ['App\Models\Post']);

        $this->assertCount(2, $results);
        $this->assertEquals(5, $total);
    }

    public function test_spellcheck_suggestions(): void
    {
        $engine = $this->createEngine();

        $engine->upsert('App\Models\Post', 1, [
            'title' => 'laravel',
            'body' => 'php framework',
        ]);
        $engine->upsert('App\Models\Post', 2, [
            'title' => 'laravel package',
            'body' => 'illumi search module',
        ]);

        $suggestions = $engine->suggest('laravell');
        $this->assertNotEmpty($suggestions);
        $this->assertContains('laravel', $suggestions);

        $suggestions = $engine->suggest('framwork');
        $this->assertContains('framework', $suggestions);
    }

    public function test_rebuild_vocab_from_scratch(): void
    {
        $engine = $this->createEngine();

        $engine->upsert('App\Models\Post', 1, [
            'title' => 'php framework',
            'body' => 'laravel symfony',
        ]);
        $engine->upsert('App\Models\Post', 2, [
            'title' => 'data science',
            'body' => 'python machine learning',
        ]);

        // rebuildVocabFromScratch should repopulate the vocab table
        $engine->rebuildVocabFromScratch();

        // After rebuild, suggest should still work
        $suggestions = $engine->suggest('framwork');
        $this->assertNotEmpty($suggestions, 'Suggest should still return results after rebuild');
        $this->assertContains('framework', $suggestions);

        $suggestions = $engine->suggest('scienc');
        $this->assertContains('science', $suggestions);
    }

    public function test_rebuild_index_from_scratch(): void
    {
        $engine = $this->createEngine();

        $engine->upsert('App\Models\Post', 1, [
            'title' => 'php programming',
            'body' => 'learn php today',
        ]);

        try {
            $engine->rebuildIndexFromScratch();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->assertTrue(false, 'rebuildIndexFromScratch should not throw: ' . $e->getMessage());
        }
    }

    public function test_engine_status_returns_expected_keys(): void
    {
        $engine = $this->createEngine();
        $status = $engine->getEngineStatus();

        $this->assertArrayHasKey('driver', $status);
        $this->assertArrayHasKey('engine_version', $status);
        $this->assertArrayHasKey('connection', $status);
        $this->assertArrayHasKey('database_size', $status);
        $this->assertArrayHasKey('max_weight', $status);
        $this->assertArrayHasKey('collation', $status);

        $this->assertSame('MySQL FULLTEXT', $status['driver']);
        $this->assertStringContainsString('MySQL', $status['engine_version']);
    }

    public function test_tenant_prefix_is_applied_to_tables(): void
    {
        if (! $this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL connection not available.');
        }

        config(['illumi-search.tenancy' => ['enabled' => true]]);
        app(\Moaines\IllumiSearch\TenantManager::class)->setResolver(fn () => 'tenant_42');

        $rawConn = DB::connection(MySqlEngine::CONNECTION_NAME);
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_index');
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_config');
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_vocab');

        $engine = new MySqlEngine;
        $engine->createTable('App\Models\Post', ['title', 'body']);

        $tables = $engine->listIndexTables();
        $this->assertContains('tenant_42_search_index', $tables);

        $engine->upsert('App\Models\Post', 1, ['title' => 'test', 'body' => 'content']);
        $results = $engine->search('test', ['App\Models\Post'], 10);
        $this->assertCount(1, $results);

        // Verify the prefixed table has the data
        $data = $rawConn->table('tenant_42_search_index')->where('model_type', 'App\Models\Post')->count();
        $this->assertEquals(1, $data);
        $data = $rawConn->table('tenant_42_search_vocab')->count();
        $this->assertGreaterThan(0, $data);

        $engine->dropTable('App\Models\Post');
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_index');
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_config');
        $rawConn->statement('DROP TABLE IF EXISTS tenant_42_search_vocab');

        config(['illumi-search.tenancy' => ['enabled' => false]]);
        app(\Moaines\IllumiSearch\TenantManager::class)->setResolver(fn () => null);
    }

    public function test_drop_index_table(): void
    {
        $engine = $this->createEngine();
        $engine->upsert('App\Models\Post', 1, ['title' => 'test', 'body' => 'data']);

        $this->assertEquals(1, $engine->count('test', ['App\Models\Post']));
        $engine->dropIndexTable('App\Models\Post');
        $this->assertEquals(0, $engine->count('test', ['App\Models\Post']));
    }

    public function test_list_index_tables(): void
    {
        $engine = $this->createEngine();
        $tables = $engine->listIndexTables();
        $this->assertContains('search_index', $tables);
    }

    public function test_integrity_check(): void
    {
        $engine = $this->createEngine();
        $this->assertTrue($engine->integrityCheck('App\Models\Post'));
    }

    public function test_full_integrity_check(): void
    {
        $engine = $this->createEngine();
        $result = $engine->fullIntegrityCheck();
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['passed']);
    }

    public function test_get_indexed_model_classes(): void
    {
        $engine = $this->createEngine();
        $this->assertIsArray($engine->getIndexedModelClasses());
    }

    public function test_database_size(): void
    {
        $engine = $this->createEngine();
        $size = $engine->getDatabaseSize();
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
    }
}

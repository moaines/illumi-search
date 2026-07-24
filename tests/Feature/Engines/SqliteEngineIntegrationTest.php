<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\TenantManager;

class SqliteEngineIntegrationTest extends AbstractEngineTest
{
    protected function createEngine(): Engine
    {
        $engine = $this->app->make(Engine::class);
        $engine->dropTable('App\Models\Post');
        $engine->createTable('App\Models\Post', ['title', 'body']);

        return $engine;
    }

    /** @test */
    public function table_drop_removes_access(): void
    {
        $this->assertTableDropped();
    }

    /** @test */
    public function test_engine_status_returns_expected_keys(): void
    {
        $engine = $this->createEngine();
        $status = $engine->getEngineStatus();

        $this->assertArrayHasKey('driver', $status);
        $this->assertArrayHasKey('engine_version', $status);
        $this->assertArrayHasKey('tokenizer', $status);
        $this->assertArrayHasKey('wal', $status);
        $this->assertArrayHasKey('busy_timeout', $status);

        $this->assertSame('SQLite FTS5', $status['driver']);
        $this->assertStringContainsString('FTS5', $status['engine_version']);
    }

    /** @test */
    public function test_tenant_isolation_uses_separate_database_path(): void
    {
        config(['illumi-search.tenancy' => ['enabled' => true]]);
        app(TenantManager::class)->setResolver(fn () => 'tenant_sqlite_1');

        $engine = $this->app->make(Engine::class);
        $path = $engine->getDatabasePath();

        $this->assertStringContainsString('tenants/tenant_sqlite_1', $path,
            'Tenant database path should contain tenant ID');

        // Index data in tenant context
        $engine->dropTable('App\Models\Post');
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'tenant data', 'body' => 'secret']);

        $results = $engine->search('tenant', ['App\Models\Post'], 10);
        $this->assertCount(1, $results);

        // Switch to a different tenant
        app(TenantManager::class)->setResolver(fn () => 'tenant_sqlite_2');
        $this->app->forgetInstance(Engine::class);
        $engine2 = $this->app->make(Engine::class);
        $path2 = $engine2->getDatabasePath();

        $this->assertNotSame($path, $path2,
            'Different tenants should use different database paths');

        // Second tenant should have no data
        $engine2->dropTable('App\Models\Post');
        $engine2->createTable('App\Models\Post', ['title', 'body']);
        $results2 = $engine2->search('tenant', ['App\Models\Post'], 10);
        $this->assertCount(0, $results2, 'Tenant 2 should not see Tenant 1 data');

        // Cleanup
        $engine->dropTable('App\Models\Post');
        $engine2->dropTable('App\Models\Post');
        @unlink($path);
        @unlink($path2);

        config(['illumi-search.tenancy' => ['enabled' => false]]);
        app(TenantManager::class)->setResolver(fn () => null);
    }

    /** @test */
    public function test_list_index_tables(): void
    {
        $engine = $this->createEngine();
        $tables = $engine->listIndexTables();
        $this->assertNotEmpty($tables);
    }

    /** @test */
    public function test_drop_index_table(): void
    {
        $engine = $this->createEngine();
        $engine->upsert('App\Models\Post', 1, ['title' => 'test drop', 'body' => 'data']);

        $this->assertEquals(1, $engine->count('test drop', ['App\Models\Post']));
        $engine->dropIndexTable($engine->tableName('App\Models\Post'));
        $this->assertEquals(0, $engine->count('test drop', ['App\Models\Post']));
    }

    /** @test */
    public function test_table_name_uses_prefix(): void
    {
        $engine = $this->createEngine();
        $name = $engine->tableName('App\Models\Post');
        $this->assertStringStartsWith('illumi_search_idx_', $name,
            'SQLite table names should use the configured prefix');
        $this->assertStringContainsString('app_models_post', $name);
    }
}

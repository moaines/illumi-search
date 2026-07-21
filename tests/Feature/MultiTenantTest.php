<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Tests\TestCase;

class MultiTenantTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('illumi-search.tenancy.enabled', true);
    }

    public function test_tenant_manager_resolves_tenant_id(): void
    {
        $manager = $this->app->make(TenantManager::class);
        $manager->setResolver(fn () => 'tenant_42');

        $this->assertTrue($manager->enabled());
        $this->assertEquals('tenant_42', $manager->resolve());
    }

    public function test_tenant_manager_returns_null_when_no_resolver(): void
    {
        $manager = $this->app->make(TenantManager::class);

        $this->assertFalse($manager->enabled());
        $this->assertNull($manager->resolve());
    }

    public function test_tenant_database_path_includes_tenant_id(): void
    {
        $manager = $this->app->make(TenantManager::class);
        $manager->setResolver(fn () => 'acme');

        $basePath = storage_path('app/search/fts-index.sqlite');
        $tenantPath = $manager->tenantDatabasePath($basePath);

        $this->assertStringContainsString('tenants/acme/', $tenantPath);
        $this->assertStringEndsWith('fts-index.sqlite', $tenantPath);
    }

    public function test_tenant_database_path_unchanged_when_disabled(): void
    {
        $this->app['config']->set('illumi-search.tenancy.enabled', false);

        $manager = $this->app->make(TenantManager::class);
        $manager->setResolver(fn () => 'acme');

        $basePath = storage_path('app/search/fts-index.sqlite');
        $tenantPath = $manager->tenantDatabasePath($basePath);

        $this->assertEquals($basePath, $tenantPath);
    }

    public function test_different_tenants_get_different_paths(): void
    {
        $manager = $this->app->make(TenantManager::class);
        $basePath = storage_path('app/search/fts-index.sqlite');

        $manager->setResolver(fn () => 'tenant_a');
        $pathA = $manager->tenantDatabasePath($basePath);

        $manager->setResolver(fn () => 'tenant_b');
        $pathB = $manager->tenantDatabasePath($basePath);

        $this->assertNotEquals($pathA, $pathB);
        $this->assertStringContainsString('tenants/tenant_a/', $pathA);
        $this->assertStringContainsString('tenants/tenant_b/', $pathB);
    }

    public function test_tenant_path_does_not_duplicate_app_fts(): void
    {
        $manager = $this->app->make(TenantManager::class);
        $manager->setResolver(fn () => 'acme');
        $basePath = storage_path('app/search/fts-index.sqlite');
        $tenantPath = $manager->tenantDatabasePath($basePath);

        $this->assertStringNotContainsString('app/search/app/search', $tenantPath);
        $this->assertStringContainsString('tenants/acme/', $tenantPath);
    }

    public function test_data_isolation_between_tenants(): void
    {
        $tmpDir = sys_get_temp_dir();
        $pathA = $tmpDir . '/illumi_test_tenant_a.sqlite';
        $pathB = $tmpDir . '/illumi_test_tenant_b.sqlite';

        @unlink($pathA);
        @unlink($pathB);

        $engineA = new SqliteEngine(databasePath: $pathA);
        $engineB = new SqliteEngine(databasePath: $pathB);

        $engineA->createTable('App\Models\Post', ['title', 'body']);
        $engineB->createTable('App\Models\Post', ['title', 'body']);

        $engineA->upsert('App\Models\Post', 1, ['title' => 'SECRET_A data', 'body' => 'only tenant A']);
        $engineB->upsert('App\Models\Post', 1, ['title' => 'SECRET_B data', 'body' => 'only tenant B']);

        $resultsA = $engineA->search('SECRET', ['App\Models\Post'], 10);
        $resultsB = $engineB->search('SECRET', ['App\Models\Post'], 10);

        $this->assertCount(1, $resultsA, 'Tenant A should find its own data');
        $this->assertEquals(1, $resultsA[0]->modelId, 'Tenant A should find post 1');
        $this->assertStringContainsString('SECRET_A', $resultsA[0]->title, 'Tenant A should get A data');

        $this->assertCount(1, $resultsB, 'Tenant B should find its own data');
        $this->assertEquals(1, $resultsB[0]->modelId, 'Tenant B should find post 1');
        $this->assertStringContainsString('SECRET_B', $resultsB[0]->title, 'Tenant B should get B data, not A data');

        @unlink($pathA);
        @unlink($pathB);
    }
}

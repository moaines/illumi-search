<?php

namespace Moaines\IllumiSearch\Tests\Feature;

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
}

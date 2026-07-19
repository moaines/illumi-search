<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Tests\TestCase;

class TenantManagerTest extends TestCase
{
    public function test_enabled_returns_false_without_resolver(): void
    {
        $manager = new TenantManager;

        $this->assertFalse($manager->enabled());
    }

    public function test_enabled_returns_true_when_resolver_set_and_config_enabled(): void
    {
        config(['illumi-search.tenancy.enabled' => true]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'abc');

        $this->assertTrue($manager->enabled());
    }

    public function test_tenant_database_path_appends_tenant_id(): void
    {
        config(['illumi-search.tenancy.enabled' => true]);
        config(['illumi-search.tenancy.directory' => 'app/search/tenants']);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'tenant_xyz');

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertStringContainsString('tenant_xyz', $path);
        $this->assertStringEndsWith('fts-index.sqlite', $path);
    }

    public function test_tenant_database_path_returns_base_path_when_disabled(): void
    {
        config(['illumi-search.tenancy.enabled' => false]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'tenant_xyz');

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertSame('/base/path/fts-index.sqlite', $path);
    }

    public function test_tenant_database_path_returns_base_path_when_tenant_id_null(): void
    {
        config(['illumi-search.tenancy.enabled' => true]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => null);

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertSame('/base/path/fts-index.sqlite', $path);
    }
}

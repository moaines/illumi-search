<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\TenantManager;
use Moaines\LaravelFts\Tests\TestCase;

class TenantManagerTest extends TestCase
{
    public function test_enabled_returns_false_without_resolver(): void
    {
        $manager = new TenantManager;

        $this->assertFalse($manager->enabled());
    }

    public function test_enabled_returns_true_when_resolver_set_and_config_enabled(): void
    {
        config(['fts.tenancy.enabled' => true]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'abc');

        $this->assertTrue($manager->enabled());
    }

    public function test_tenant_database_path_appends_tenant_id(): void
    {
        config(['fts.tenancy.enabled' => true]);
        config(['fts.tenancy.directory' => 'app/fts/tenants']);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'tenant_xyz');

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertStringContainsString('tenant_xyz', $path);
        $this->assertStringEndsWith('fts-index.sqlite', $path);
    }

    public function test_tenant_database_path_returns_base_path_when_disabled(): void
    {
        config(['fts.tenancy.enabled' => false]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => 'tenant_xyz');

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertSame('/base/path/fts-index.sqlite', $path);
    }

    public function test_tenant_database_path_returns_base_path_when_tenant_id_null(): void
    {
        config(['fts.tenancy.enabled' => true]);

        $manager = new TenantManager;
        $manager->setResolver(fn () => null);

        $path = $manager->tenantDatabasePath('/base/path/fts-index.sqlite');

        $this->assertSame('/base/path/fts-index.sqlite', $path);
    }
}

<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Tests\TestCase;

class AuthorizationTenancyTest extends TestCase
{
    public function test_tenant_enabled_config(): void
    {
        config(['illumi-search.tenancy' => ['enabled' => true]]);
        $tenantId = app(TenantManager::class)->tenantId();
        $this->assertNull($tenantId, 'Without resolver, tenant ID should be null');
    }

    public function test_tenant_resolver_returns_id(): void
    {
        config(['illumi-search.tenancy' => ['enabled' => true]]);
        app(TenantManager::class)->setResolver(fn () => 'my_tenant');
        $tenantId = app(TenantManager::class)->tenantId();
        $this->assertSame('my_tenant', $tenantId);
    }

    public function test_tenant_disabled_returns_null(): void
    {
        config(['illumi-search.tenancy' => ['enabled' => false]]);
        app(TenantManager::class)->setResolver(fn () => 'my_tenant');
        $tenantId = app(TenantManager::class)->tenantId();
        $this->assertNull($tenantId, 'Disabled tenancy should return null even with resolver');
    }
}

<?php

namespace Moaines\IllumiSearch\Concerns;

use Moaines\IllumiSearch\TenantManager;

trait HasTenant
{
    private function tenantId(): ?string
    {
        return app(TenantManager::class)->tenantId();
    }
}

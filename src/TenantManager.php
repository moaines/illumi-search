<?php

namespace Moaines\IllumiSearch;

use Closure;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;

class TenantManager
{
    private ?Closure $resolver = null;

    public function setResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function resolve(): ?string
    {
        if ($this->resolver === null) {
            return null;
        }

        $tenantId = call_user_func($this->resolver);

        return $tenantId !== null ? (string) $tenantId : null;
    }

    public function enabled(): bool
    {
        return app(IllumiSearchConfig::class)->tenancyEnabled() && $this->resolver !== null;
    }

    public function tenantDatabasePath(string $basePath): string
    {
        if (! $this->enabled()) {
            return $basePath;
        }

        $tenantId = $this->resolve();

        if ($tenantId === null) {
            return $basePath;
        }

        $dir = app(IllumiSearchConfig::class)->tenancyDirectory();
        $filename = basename($basePath);

        return storage_path($dir . '/' . $tenantId . '/' . $filename);
    }

    public function tenantId(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->resolve();
    }
}

<?php

namespace Moaines\LaravelFts;

use Closure;

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
        return config('fts.tenancy.enabled', false) && $this->resolver !== null;
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

        $dir = config('fts.tenancy.directory', 'app/fts/tenants');

        return str_replace(
            'app/fts/',
            $dir . '/' . $tenantId . '/',
            $basePath
        );
    }
}

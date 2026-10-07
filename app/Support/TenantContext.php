<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * Holds the tenant the current request (or job) is acting for.
 *
 * By default it follows the authenticated user. Jobs, commands and tests can
 * pin a tenant explicitly with run()/set(), and super-admin or cross-tenant
 * service code can opt out with withoutTenant().
 */
class TenantContext
{
    private bool $overridden = false;

    private ?int $tenantId = null;

    public function id(): ?int
    {
        if ($this->overridden) {
            return $this->tenantId;
        }

        return Auth::hasUser() ? Auth::user()->tenant_id : null;
    }

    public function has(): bool
    {
        return $this->id() !== null;
    }

    public function tenant(): ?Tenant
    {
        $id = $this->id();

        return $id ? Tenant::find($id) : null;
    }

    public function set(?int $tenantId): void
    {
        $this->overridden = true;
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->overridden = false;
        $this->tenantId = null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous context afterwards.
     */
    public function run(?int $tenantId, callable $callback): mixed
    {
        [$wasOverridden, $previous] = [$this->overridden, $this->tenantId];
        $this->set($tenantId);

        try {
            return $callback();
        } finally {
            $this->overridden = $wasOverridden;
            $this->tenantId = $previous;
        }
    }
}

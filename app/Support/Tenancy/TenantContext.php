<?php

namespace App\Support\Tenancy;

use App\Enums\TenantRole;
use App\Models\Tenant;
use RuntimeException;

/**
 * Holds the tenant (company) the current request operates in.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantRole $role = null;

    public function set(Tenant $tenant, ?TenantRole $role = null): void
    {
        $this->tenant = $tenant;
        $this->role = $role;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->role = null;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant is set for the current context.');
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function role(): ?TenantRole
    {
        return $this->role;
    }
}

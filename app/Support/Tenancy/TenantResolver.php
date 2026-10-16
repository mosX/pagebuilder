<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\User;

/**
 * Picks the company a user works in: their saved current company when still accessible,
 * otherwise the first active company they belong to.
 */
class TenantResolver
{
    public function resolve(User $user): ?Tenant
    {
        $tenant = $this->current($user) ?? $this->fallback($user);

        if ($tenant && $user->current_tenant_id !== $tenant->id) {
            $user->switchTenant($tenant);
        }

        return $tenant;
    }

    public function canEnter(User $user, Tenant $tenant): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $tenant->is_active && $user->membershipIn($tenant) !== null;
    }

    private function current(User $user): ?Tenant
    {
        $current = $user->currentTenant;

        return $current && $this->canEnter($user, $current) ? $current : null;
    }

    private function fallback(User $user): ?Tenant
    {
        $fallback = $user->tenants()->where('is_active', true)->orderBy('name')->first();

        // A super admin without own memberships still needs some company to operate in.
        if (! $fallback && $user->is_super_admin) {
            $fallback = Tenant::query()->orderBy('name')->first();
        }

        return $fallback;
    }
}

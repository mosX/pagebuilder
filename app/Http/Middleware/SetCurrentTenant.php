<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the company the authenticated user works in and binds it to the TenantContext.
 * Users without any company are sent to onboarding.
 */
class SetCurrentTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = $this->resolve($user);

        if (! $tenant) {
            return redirect()->route('onboarding.create');
        }

        if ($user->current_tenant_id !== $tenant->id) {
            $user->switchTenant($tenant);
        }

        $this->context->set($tenant, $user->roleIn($tenant));

        return $next($request);
    }

    private function resolve(User $user): ?Tenant
    {
        $current = $user->currentTenant;

        if ($current && $this->canEnter($user, $current)) {
            return $current;
        }

        return $user->tenants()->where('is_active', true)->orderBy('name')->first();
    }

    private function canEnter(User $user, Tenant $tenant): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $tenant->is_active && $user->membershipIn($tenant) !== null;
    }
}

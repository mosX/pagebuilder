<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the company the authenticated user works in to the TenantContext.
 * Users without any company get a 409 `no_tenant` so the SPA can send them to onboarding.
 */
class SetCurrentTenant
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = $this->resolver->resolve($user);

        if (! $tenant) {
            return response()->json([
                'message' => 'Вы не состоите ни в одной компании.',
                'code' => 'no_tenant',
            ], 409);
        }

        $this->context->set($tenant, $user->roleIn($tenant));

        return $next($request);
    }
}

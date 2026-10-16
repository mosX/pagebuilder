<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'is_super_admin']),
            ],
            // Resolved lazily: SetCurrentTenant runs after this middleware is constructed.
            'tenant' => fn () => $this->tenantProps($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenantProps(?User $user): ?array
    {
        $context = app(TenantContext::class);

        if (! $user || ! $context->check()) {
            return null;
        }

        $tenant = $context->tenant();
        $role = $context->role();

        return [
            'current' => $tenant->only(['id', 'name', 'slug']),
            'role' => $role ? ['value' => $role->value, 'label' => $role->label()] : null,
            'permissions' => array_map(fn (Permission $p) => $p->value, $role?->permissions() ?? []),
            'available' => $user->tenants()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['tenants.id', 'tenants.name'])
                ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name])
                ->when(
                    fn ($list) => ! $list->contains('id', $tenant->id),
                    fn ($list) => $list->prepend(['id' => $tenant->id, 'name' => $tenant->name]),
                )
                ->values(),
        ];
    }
}

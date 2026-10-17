<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the SPA needs to know about the signed-in user: profile, current company,
 * role and resolved permissions, and the companies they can switch to.
 *
 * @property User $resource
 */
class SessionResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(User $user, private ?Tenant $tenant)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'user' => [...$user->only(['id', 'name', 'email', 'is_super_admin']), 'email_verified' => $user->email_verified_at !== null],
            'tenant' => $this->tenant ? $this->tenantState($user, $this->tenant) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantState(User $user, Tenant $tenant): array
    {
        $role = $user->roleIn($tenant);

        $available = $user->tenants()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['tenants.id', 'tenants.name'])
            ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name]);

        if (! $available->contains('id', $tenant->id)) {
            $available->prepend(['id' => $tenant->id, 'name' => $tenant->name]);
        }

        return [
            'current' => [...$tenant->only(['id', 'name', 'slug']), 'site_url' => $tenant->siteUrl()],
            'role' => $role instanceof TenantRole ? ['value' => $role->value, 'label' => $role->label()] : null,
            'permissions' => array_map(fn (Permission $p) => $p->value, $role?->permissions() ?? []),
            'available' => $available->values(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Site\SiteTemplates;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Choosing the company the user works in: switching between companies and
 * creating the first one during onboarding. Both return the refreshed session.
 */
class TenantSessionController extends Controller
{
    public function __construct(
        private TenantResolver $resolver,
    ) {}

    public function switch(Request $request, Tenant $tenant): SessionResource
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->resolver->canEnter($user, $tenant), 403);

        $user->switchTenant($tenant);

        return new SessionResource($user, $tenant);
    }

    public function create(Request $request, SiteTemplates $templates): SessionResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template' => ['nullable', Rule::in($templates->keys($request->user() instanceof User ? $request->user() : null))],
        ]);

        /** @var User $user */
        $user = $request->user();
        $max = (int) config('tenancy.max_owned_companies');

        if (! $user->is_super_admin && $user->tenants()->wherePivot('role', TenantRole::Owner->value)->count() >= $max) {
            throw ValidationException::withMessages(['name' => "Можно владеть не больше чем {$max} компаниями."]);
        }

        $tenant = DB::transaction(function () use ($data, $user) {
            $tenant = Tenant::create(['name' => $data['name']]);
            $tenant->addMember($user, TenantRole::Owner);
            $user->switchTenant($tenant);

            return $tenant;
        });

        $templates->apply($tenant, $data['template'] ?? SiteTemplates::BLANK);

        return new SessionResource($user, $tenant);
    }
}

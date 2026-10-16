<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Choosing the company the user works in: switching between companies and
 * creating the first one during onboarding. Both return the refreshed session.
 */
class TenantSessionController extends Controller
{
    public function __construct(
        private TenantResolver $resolver,
        private PagesCollection $pages,
    ) {}

    public function switch(Request $request, Tenant $tenant): SessionResource
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->resolver->canEnter($user, $tenant), 403);

        $user->switchTenant($tenant);

        return new SessionResource($user, $tenant);
    }

    public function create(Request $request): SessionResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $tenant = DB::transaction(function () use ($data, $user) {
            $tenant = Tenant::create(['name' => $data['name']]);
            $tenant->addMember($user, TenantRole::Owner);
            $user->switchTenant($tenant);

            return $tenant;
        });

        $this->pages->ensure($tenant);

        return new SessionResource($user, $tenant);
    }
}

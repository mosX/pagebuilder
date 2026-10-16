<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a signed-in user without any company create one.
 */
class OnboardingController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('onboarding/CreateCompany');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($data, $user) {
            $tenant = Tenant::create(['name' => $data['name']]);
            $tenant->addMember($user, TenantRole::Owner);
            $user->switchTenant($tenant);
        });

        Inertia::flash('success', 'Компания создана.');

        return redirect()->route('admin.dashboard');
    }
}

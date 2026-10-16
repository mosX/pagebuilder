<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SwitchTenantController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $allowed = $user->is_super_admin
            || ($tenant->is_active && $user->membershipIn($tenant) !== null);

        abort_unless($allowed, 403);

        $user->switchTenant($tenant);

        Inertia::flash('success', "Вы работаете в компании «{$tenant->name}».");

        return redirect()->route('admin.dashboard');
    }
}

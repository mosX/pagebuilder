<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $context->tenant();

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'members' => $tenant->members()->count(),
                'tenants' => $user->is_super_admin ? Tenant::count() : null,
                'users' => $user->is_super_admin ? User::count() : null,
            ],
        ]);
    }
}

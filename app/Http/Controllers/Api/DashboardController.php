<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'members' => $context->tenant()->members()->count(),
            'tenants' => $user->is_super_admin ? Tenant::count() : null,
            'users' => $user->is_super_admin ? User::count() : null,
        ]);
    }
}

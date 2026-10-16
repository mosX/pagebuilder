<?php

namespace App\Http\Controllers\Api\Super;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use App\Support\Tenancy\Subdomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform-wide company management, available to super admins only.
 */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();

        $tenants = Tenant::query()
            ->withCount('members')
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [...$tenant->toArray(), 'site_url' => $tenant->siteUrl()]);

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [...Subdomains::rules(required: false), 'unique:tenants,slug'],
            'owner_email' => ['nullable', 'email', 'exists:users,email'],
        ], Subdomains::messages());

        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ]);

            if (! empty($data['owner_email'])) {
                $tenant->addMember(User::where('email', $data['owner_email'])->firstOrFail(), TenantRole::Owner);
            }

            return $tenant;
        });

        app(PagesCollection::class)->ensure($tenant);

        return response()->json(['message' => 'Компания создана.', 'tenant' => $tenant], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [...Subdomains::rules(), Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'is_active' => ['required', 'boolean'],
        ], Subdomains::messages());

        $tenant->update($data);

        return response()->json(['message' => 'Компания обновлена.', 'tenant' => $tenant]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return response()->json(['message' => "Компания «{$tenant->name}» удалена."]);
    }
}

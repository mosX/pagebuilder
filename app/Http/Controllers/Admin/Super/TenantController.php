<?php

namespace App\Http\Controllers\Admin\Super;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide company management, available to super admins only.
 */
class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $tenants = Tenant::query()
            ->withCount('members')
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/super/tenants/Index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash:ascii', 'lowercase', 'unique:tenants,slug'],
            'owner_email' => ['nullable', 'email', 'exists:users,email'],
        ]);

        DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ]);

            if (! empty($data['owner_email'])) {
                $tenant->addMember(User::where('email', $data['owner_email'])->firstOrFail(), TenantRole::Owner);
            }
        });

        Inertia::flash('success', 'Компания создана.');

        return back();
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', 'lowercase', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        $tenant->update($data);

        Inertia::flash('success', 'Компания обновлена.');

        return back();
    }

    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->delete();

        Inertia::flash('success', "Компания «{$tenant->name}» удалена.");

        return back();
    }
}

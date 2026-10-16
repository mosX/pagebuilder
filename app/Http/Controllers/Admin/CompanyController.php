<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function edit(): Response
    {
        $tenant = $this->context->tenant();

        return Inertia::render('admin/company/Edit', [
            'company' => $tenant->only(['id', 'name', 'slug', 'created_at']),
            'canUpdate' => Gate::allows('company.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', 'lowercase', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
        ]);

        $tenant->update($data);

        Inertia::flash('success', 'Данные компании сохранены.');

        return back();
    }
}

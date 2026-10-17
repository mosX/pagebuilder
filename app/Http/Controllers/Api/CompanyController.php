<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit\ActivityLog;
use App\Support\Site\Scheduling;
use App\Support\Tenancy\Subdomains;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'company' => [...$this->context->tenant()->only(['id', 'name', 'slug', 'timezone', 'created_at']), 'site_url' => $this->context->tenant()->siteUrl()],
            'timezones' => timezone_identifiers_list(),
            // The subdomain is {slug}.{site_domain}, whatever the primary address of the site is.
            'site_domain' => Subdomains::domain(),
            'can_update' => Gate::allows('company.update'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [...Subdomains::rules(), Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ], Subdomains::messages());

        $tenant->forceFill($data)->save();
        Scheduling::forget();
        app(ActivityLog::class)->record('company.updated', $tenant->name, ['slug' => $tenant->slug]);

        return response()->json([
            'message' => 'Данные компании сохранены.',
            'company' => $tenant->only(['id', 'name', 'slug', 'timezone', 'created_at']),
        ]);
    }
}

<?php

namespace App\Support\Site;

use App\Models\SiteMember;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Builds the CurrentSite of a site request from its route: {site} (the company slug),
 * an optional {locale} prefix, and whether it is the preview path on the platform domain.
 */
class SiteResolver
{
    public function __construct(
        private SiteDesign $design,
        private SiteLanguages $languages,
        private SiteAccounts $accounts,
        private TenantContext $context,
    ) {}

    /**
     * @param  bool  $ignoreLocale  Treat the {locale} segment as not being a language (see SitePageController::page).
     */
    public function resolve(Request $request, bool $ignoreLocale = false): CurrentSite
    {
        $slug = (string) $request->route('site');
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        abort_unless($tenant !== null, 404);

        // Site visitors are scoped to the company like everything else.
        $this->context->set($tenant);

        $settings = $this->design->for($tenant);
        $languages = $this->languages->of($settings);
        $param = $ignoreLocale ? null : $request->route('locale');

        abort_if(is_string($param) && ! in_array($param, $languages['extra'], true), 404);

        $preview = $request->routeIs('site.preview.*');
        $rootUrl = $preview ? "/site/{$tenant->slug}" : '';
        $account = $this->accounts->of($settings);

        return new CurrentSite(
            host: $request->getSchemeAndHttpHost(),
            tenant: $tenant,
            settings: $settings,
            rootUrl: $rootUrl,
            baseUrl: is_string($param) ? "{$rootUrl}/{$param}" : $rootUrl,
            languages: $languages,
            localeCode: is_string($param) ? $param : $languages['default'],
            account: $account,
            preview: $preview,
            member: $account['enabled'] ? $this->member($tenant) : null,
        );
    }

    private function member(Tenant $tenant): ?SiteMember
    {
        $guard = Auth::guard('member');
        $member = $guard->user();

        if (! $member instanceof SiteMember || $member->tenant_id !== $tenant->id) {
            return null;
        }

        // Blocking a visitor ends their session at the next request.
        if ($member->is_blocked) {
            $guard->logout();

            return null;
        }

        return $member;
    }
}

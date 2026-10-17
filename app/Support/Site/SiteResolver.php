<?php

namespace App\Support\Site;

use App\Models\SiteMember;
use App\Models\Tenant;
use App\Support\Tenancy\CustomDomains;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Builds the CurrentSite of a site request from its route: {site} (the company slug) or
 * {host} (a company's own domain), an optional {locale} prefix, and whether it is the
 * preview path on the platform domain.
 *
 * A site with a primary domain lives there: its subdomain and other domains redirect to it.
 */
class SiteResolver
{
    public function __construct(
        private SiteDesign $design,
        private SiteLanguages $languages,
        private SiteAccounts $accounts,
        private TenantContext $context,
        private CustomDomains $domains,
    ) {}

    /**
     * The active company a site request is for, by subdomain slug or own domain.
     */
    public function tenantFor(Request $request): ?Tenant
    {
        $host = $request->route('host');

        if (is_string($host)) {
            $domain = $this->domains->find($host);

            return $domain ? Tenant::where('is_active', true)->find($domain->tenant_id) : null;
        }

        return Tenant::where('slug', (string) $request->route('site'))->where('is_active', true)->first();
    }

    /**
     * @param  bool  $ignoreLocale  Treat the {locale} segment as not being a language (see SitePageController::page).
     */
    public function resolve(Request $request, bool $ignoreLocale = false): CurrentSite
    {
        $tenant = $this->tenantFor($request);

        abort_unless($tenant !== null, 404);

        $this->redirectFromPreviewPath($request, $tenant);
        $this->redirectToPrimaryDomain($request, $tenant);

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

    /**
     * With the preview path turned off, /site/{slug}/… leads to the same page on the site itself.
     */
    private function redirectFromPreviewPath(Request $request, Tenant $tenant): void
    {
        if (! $request->routeIs('site.preview.*') || config('tenancy.preview_path')) {
            return;
        }

        $path = Str::after($request->getRequestUri(), "/site/{$tenant->slug}");

        throw new HttpResponseException(redirect()->away($tenant->siteUrl().($path === '' ? '/' : $path), 301));
    }

    /**
     * The subdomain and secondary domains of a site with a primary domain redirect there
     * (the preview path on the platform domain stays).
     */
    private function redirectToPrimaryDomain(Request $request, Tenant $tenant): void
    {
        $primary = $this->domains->primaryOf($tenant);

        if (! $primary || $request->routeIs('site.preview.*') || Str::lower($request->getHost()) === $primary) {
            return;
        }

        throw new HttpResponseException(redirect()->away($tenant->siteUrl().$request->getRequestUri(), 301));
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

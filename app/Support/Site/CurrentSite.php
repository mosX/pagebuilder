<?php

namespace App\Support\Site;

use App\Models\Collection;
use App\Models\SiteMember;
use App\Models\SiteSettings;
use App\Models\Tenant;
use App\Support\Blocks\RenderContext;

/**
 * The company site a request is for: where it is served (subdomain or preview path),
 * in which language, and the signed-in visitor, if any.
 */
final readonly class CurrentSite
{
    /**
     * @param  string  $rootUrl  '' on the company subdomain, '/site/{slug}' on the preview path.
     * @param  string  $baseUrl  $rootUrl plus '/{locale}' for an additional language.
     * @param  array{default: string, extra: list<string>}  $languages
     * @param  string  $localeCode  The language of this request (the default one included).
     * @param  array{enabled: bool, registration: bool, collections: list<array{collection: string, email_field: string}>}  $account
     */
    public function __construct(
        /** Scheme and host of the request, for redirects. */
        public string $host,
        public Tenant $tenant,
        public SiteSettings $settings,
        public string $rootUrl,
        public string $baseUrl,
        public array $languages,
        public string $localeCode,
        public array $account,
        public bool $preview,
        public ?SiteMember $member,
    ) {}

    /**
     * The language of translated content: null for the site's default language.
     */
    public function locale(): ?string
    {
        return $this->localeCode === $this->languages['default'] ? null : $this->localeCode;
    }

    /**
     * A site URL relative to the host: url('') is the home page, url('search') the search page.
     */
    public function url(string $path = ''): string
    {
        $path = trim($path, '/');

        return $path === '' ? ($this->baseUrl ?: '/') : "{$this->baseUrl}/{$path}";
    }

    /**
     * An absolute URL of a site path on the current host, for redirects.
     */
    public function absolute(string $url): string
    {
        return $this->host.$url;
    }

    /**
     * The same path in another language (relative to the host, or absolute on the company subdomain).
     */
    public function urlIn(string $locale, string $path = '', bool $absolute = false): string
    {
        $root = $absolute ? $this->tenant->siteUrl() : $this->rootUrl;
        $base = $locale === $this->languages['default'] ? $root : "{$root}/{$locale}";
        $path = trim($path, '/');

        if ($path === '') {
            return $absolute ? "{$base}/" : ($base ?: '/');
        }

        return "{$base}/{$path}";
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    public function context(?Collection $collection = null, ?array $record = null, int $page = 1): RenderContext
    {
        return new RenderContext($this->tenant, $this->baseUrl, $collection, $record, $page, locale: $this->locale());
    }
}

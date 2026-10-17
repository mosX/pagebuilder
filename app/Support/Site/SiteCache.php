<?php

namespace App\Support\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Full-page cache of company sites for guests.
 *
 * Every company has a cache version; any change of its content (logged activity, record
 * events, publishing) replaces the version, so all its cached pages become stale at once
 * without listing keys. Entries also expire after a TTL as a safety net.
 */
class SiteCache
{
    public function enabled(): bool
    {
        return (bool) config('tenancy.page_cache.enabled');
    }

    public function ttl(): int
    {
        return max(1, (int) config('tenancy.page_cache.ttl'));
    }

    /**
     * The cache key of a page request: host and path plus the list page number,
     * the only query parameter pages depend on (tracking parameters do not split the cache).
     */
    public function key(int $tenantId, Request $request): string
    {
        $page = max(1, $request->integer('page', 1));

        return "site-page:{$tenantId}:{$this->version($tenantId)}:".sha1($request->getHost().'|'.$request->getPathInfo().'|'.$page);
    }

    public function version(int $tenantId): string
    {
        return (string) Cache::get($this->versionKey($tenantId), '0');
    }

    /**
     * Makes every cached page of the company stale.
     */
    public function flush(int $tenantId): void
    {
        Cache::forever($this->versionKey($tenantId), bin2hex(random_bytes(6)));
    }

    private function versionKey(int $tenantId): string
    {
        return "site-page-version:{$tenantId}";
    }
}

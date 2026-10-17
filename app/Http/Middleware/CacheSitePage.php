<?php

namespace App\Http\Middleware;

use App\Support\Site\SiteCache;
use App\Support\Site\SiteResolver;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves company site pages to guests from the page cache.
 *
 * Signed-in visitors always get a fresh page (their header shows their name), and
 * only successful HTML pages are stored: redirects (pages for members), 404s and
 * service pages (search, account) are never cached.
 */
class CacheSitePage
{
    public function __construct(
        private SiteCache $cache,
        private SiteResolver $sites,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! $this->cache->enabled() || ! $request->isMethod('GET') || $this->isMember($request)) {
            return $next($request);
        }

        $tenantId = $this->sites->tenantFor($request)?->id;

        if (! is_int($tenantId)) {
            return $next($request);
        }

        $key = $this->cache->key($tenantId, $request);
        $cached = Cache::get($key);

        if (is_array($cached) && is_string($cached['html'] ?? null)) {
            return new Response($cached['html'], 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Site-Cache' => 'hit',
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200 && $response instanceof Response && is_string($response->getContent())) {
            Cache::put($key, ['html' => $response->getContent()], $this->cache->ttl());
            $response->headers->set('X-Site-Cache', 'miss');
        }

        return $response;
    }

    /**
     * A signed-in site visitor (session or "remember me" cookie), known without loading the account.
     */
    private function isMember(Request $request): bool
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('member');

        return ($request->hasSession() && $request->session()->has($guard->getName()))
            || $request->cookies->has($guard->getRecallerName());
    }
}

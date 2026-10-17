<?php

namespace App\Http\Middleware;

use App\Support\Site\SiteResolver;
use App\Support\Site\SiteStats;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts views of company site pages (cached ones included) after the response is sent,
 * so visitors never wait for statistics. Previews, bots and failed pages are not counted.
 */
class CountSiteVisit
{
    public function __construct(
        private SiteStats $stats,
        private SiteResolver $sites,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 200 || $request->routeIs('site.preview.*') || ! $this->stats->counts($request)) {
            return;
        }

        try {
            $tenant = $this->sites->tenantFor($request);

            if ($tenant) {
                $this->stats->record($tenant->id, $request);
            }
        } catch (Throwable $e) {
            // Statistics must never break the site.
            report($e);
        }
    }
}

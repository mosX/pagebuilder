<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\CacheSitePage;
use App\Http\Middleware\CountSiteVisit;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentTenant;
use App\Support\Tenancy\CustomDomains;
use App\Support\Tenancy\Subdomains;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum: requests from the SPA's own domain get session + CSRF on /api routes.
        $middleware->statefulApi();

        // A TLS proxy on the same server (Caddy for own domains) passes the original scheme and client IP.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Links in e-mails and redirects are built from the Host header: accept only our domains.
        $middleware->trustHosts(at: fn () => [
            '^(.+\\.)?'.preg_quote(Subdomains::domain()).'$',
            ...array_map(fn (string $host) => '^'.preg_quote($host).'$', app(CustomDomains::class)->verifiedHosts()),
        ]);

        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'super' => EnsureSuperAdmin::class,
            'api.key' => AuthenticateApiKey::class,
            'site.cache' => CacheSitePage::class,
            'site.visit' => CountSiteVisit::class,
        ]);

        // The tenant must be known before route model binding, so tenant-scoped models
        // (e.g. {collection}) are resolved inside the current company only.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentTenant::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateApiKey::class,
        );

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

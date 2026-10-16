<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetCurrentTenant;
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

        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'super' => EnsureSuperAdmin::class,
        ]);

        // The tenant must be known before route model binding, so tenant-scoped models
        // (e.g. {collection}) are resolved inside the current company only.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentTenant::class,
        );

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

use App\Http\Controllers\SitePageController;
use App\Support\Tenancy\Subdomains;
use Illuminate\Support\Facades\Route;

// Company sites, server-rendered: {slug}.{site_domain}/{page}.
Route::domain('{site}.'.Subdomains::domain())->group(function () {
    Route::get('/{slug?}', [SitePageController::class, 'host'])
        ->where('slug', '[a-z0-9-]+')
        ->name('site.host');
    Route::get('/{path}/{record}', [SitePageController::class, 'hostRecord'])
        ->where(['path' => '[a-z0-9-]+', 'record' => '[^/]+'])
        ->name('site.host.record');

    // A company subdomain serves only its site, never the admin SPA.
    Route::get('/{any}', [SitePageController::class, 'notFound'])->where('any', '^(?!api/|sanctum/|storage/|build/).*');
});

// The same site on the platform domain, for previews without wildcard DNS.
Route::get('site/{site}/{slug?}', [SitePageController::class, 'preview'])
    ->where('slug', '[a-z0-9-]+')
    ->name('site.preview');
Route::get('site/{site}/{path}/{record}', [SitePageController::class, 'previewRecord'])
    ->where(['path' => '[a-z0-9-]+', 'record' => '[^/]+'])
    ->name('site.preview.record');

// The admin is a Vue SPA: every other non-API path serves the same shell and vue-router takes over.
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api|sanctum|up|storage|build).*$')
    ->name('spa');

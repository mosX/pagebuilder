<?php

use App\Http\Controllers\ImageController;
use App\Http\Controllers\SiteAccountController;
use App\Http\Controllers\SitePageController;
use App\Support\Site\SiteLanguages;
use App\Support\Tenancy\Subdomains;
use Illuminate\Support\Facades\Route;

// Resized media library images, cached as static files (works on every domain).
Route::get('img/{width}/{path}', ImageController::class)
    ->whereNumber('width')
    ->where('path', '.+')
    ->name('image');

/*
 * Pages of a company site. Registered for the company subdomain and for the preview path,
 * each also behind a language prefix (/en/...) for sites with several languages.
 * Service pages (search, account) come before page slugs, so they take precedence.
 */
$sitePages = function () {
    Route::get('search', [SitePageController::class, 'search'])->name('search');

    Route::prefix('account')->name('account.')->controller(SiteAccountController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('login', 'loginForm')->name('login');
        Route::post('login', 'login')->middleware('throttle:20,1')->name('login.store');
        Route::get('register', 'registerForm')->name('register');
        Route::post('register', 'register')->middleware('throttle:5,1')->name('register.store');
        Route::post('logout', 'logout')->name('logout');
        Route::post('profile', 'profile')->middleware('throttle:10,1')->name('profile');
        Route::post('verify', 'resendVerification')->middleware('throttle:3,10')->name('verify.resend');
        Route::get('verify/{member}/{hash}', 'verify')->whereNumber('member')->name('verify');
    });

    Route::get('/{slug?}', [SitePageController::class, 'page'])
        ->where('slug', '[a-z0-9-]+')
        ->name('page');
    Route::get('/{path}/{record}', [SitePageController::class, 'record'])
        ->where(['path' => '[a-z0-9-]+', 'record' => '[^/]+'])
        ->name('record');
};

$siteWithLanguages = function () use ($sitePages) {
    // First, so "/en" is a language rather than a page slug.
    Route::prefix('{locale}')
        ->where(['locale' => implode('|', array_keys(SiteLanguages::LOCALES))])
        ->name('locale.')
        ->group($sitePages);

    $sitePages();
};

// Company sites, server-rendered: {slug}.{site_domain}/{page}.
Route::domain('{site}.'.Subdomains::domain())->name('site.host.')->group(function () use ($siteWithLanguages) {
    $siteWithLanguages();

    // A company subdomain serves only its site, never the admin SPA.
    Route::get('/{any}', [SitePageController::class, 'notFound'])
        ->where('any', '^(?!api/|sanctum/|storage/|build/|img/).*')
        ->name('missing');
});

// The same site on the platform domain, for previews without wildcard DNS.
Route::prefix('site/{site}')->name('site.preview.')->group($siteWithLanguages);

// The admin is a Vue SPA: every other non-API path serves the same shell and vue-router takes over.
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api|sanctum|up|storage|build|img/).*$')
    ->name('spa');

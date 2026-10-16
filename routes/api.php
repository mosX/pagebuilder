<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\CollectionFieldController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\Super\TenantController;
use App\Http\Controllers\Api\TenantSessionController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    // Public site of a company (published pages only).
    Route::middleware('throttle:120,1')->prefix('public/sites/{site}')->name('site.')->group(function () {
        Route::get('/', [SiteController::class, 'show'])->name('show');
        Route::get('pages/{slug?}', [SiteController::class, 'page'])->name('page');
    });

    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('register', [AuthController::class, 'register'])->name('register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        Route::post('tenants', [TenantSessionController::class, 'create'])->name('tenants.create');
        Route::put('tenants/{tenant}/switch', [TenantSessionController::class, 'switch'])->name('tenants.switch');

        Route::middleware('tenant')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::get('company', [CompanyController::class, 'show'])->middleware('can:company.view')->name('company.show');
            Route::put('company', [CompanyController::class, 'update'])->middleware('can:company.update')->name('company.update');

            Route::get('members', [MemberController::class, 'index'])->middleware('can:members.view')->name('members.index');
            Route::middleware('can:members.manage')->group(function () {
                Route::post('members', [MemberController::class, 'store'])->name('members.store');
                Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
                Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
            });

            Route::get('collections', [CollectionController::class, 'index'])->middleware('can:content.view')->name('collections.index');
            Route::get('collections/{collection}', [CollectionController::class, 'show'])->middleware('can:content.view')->name('collections.show');

            Route::middleware('can:schema.manage')->scopeBindings()->group(function () {
                Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');
                Route::put('collections/{collection}', [CollectionController::class, 'update'])->name('collections.update');
                Route::delete('collections/{collection}', [CollectionController::class, 'destroy'])->name('collections.destroy');
                Route::put('collections/{collection}/site', [CollectionController::class, 'updateSite'])->name('collections.site');
                Route::put('collections/{collection}/template', [CollectionController::class, 'updateTemplate'])->name('collections.template');

                Route::post('collections/{collection}/fields', [CollectionFieldController::class, 'store'])->name('collections.fields.store');
                Route::put('collections/{collection}/fields/order', [CollectionFieldController::class, 'reorder'])->name('collections.fields.reorder');
                Route::put('collections/{collection}/fields/{field}', [CollectionFieldController::class, 'update'])->name('collections.fields.update');
                Route::delete('collections/{collection}/fields/{field}', [CollectionFieldController::class, 'destroy'])->name('collections.fields.destroy');
            });

            Route::get('media', [MediaController::class, 'index'])->middleware('can:content.view')->name('media.index');
            Route::middleware('can:content.manage')->group(function () {
                Route::post('media', [MediaController::class, 'store'])->name('media.store');
                Route::put('media/{media}', [MediaController::class, 'update'])->name('media.update');
                Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
            });

            Route::post('blocks/resolve', [BlockController::class, 'resolve'])->middleware('can:content.view')->name('blocks.resolve');

            Route::prefix('collections/{collection}/records')->name('records.')->whereNumber('record')->group(function () {
                Route::middleware('can:content.view')->group(function () {
                    Route::get('/', [RecordController::class, 'index'])->name('index');
                    Route::get('options', [RecordController::class, 'options'])->name('options');
                    Route::get('{record}', [RecordController::class, 'show'])->name('show');
                });

                Route::middleware('can:content.manage')->group(function () {
                    Route::post('/', [RecordController::class, 'store'])->name('store');
                    Route::put('{record}', [RecordController::class, 'update'])->name('update');
                    Route::delete('{record}', [RecordController::class, 'destroy'])->name('destroy');
                });
            });

            Route::middleware('super')->prefix('super')->name('super.')->group(function () {
                Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
                Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
                Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
                Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
            });
        });
    });
});

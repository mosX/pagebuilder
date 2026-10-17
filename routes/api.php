<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdminSearchController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\BlockPatternController;
use App\Http\Controllers\Api\BlockVersionController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\CollectionFieldController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\FormSubmissionController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\RedirectController;
use App\Http\Controllers\Api\SchemaController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteMemberController;
use App\Http\Controllers\Api\SiteSettingsController;
use App\Http\Controllers\Api\SiteStatsController;
use App\Http\Controllers\Api\Super\TenantController;
use App\Http\Controllers\Api\TenantSessionController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    // Public site of a company (published pages only).
    // Headless API of a company, authenticated with its API key.
    Route::prefix('v1')->name('v1.')->middleware(['api.key', 'throttle:api-key'])->group(function () {
        Route::get('collections', [ContentController::class, 'collections'])->name('collections');
        Route::get('collections/{collection}/records', [ContentController::class, 'index'])->name('records.index');
        Route::post('collections/{collection}/records', [ContentController::class, 'store'])->name('records.store');
        Route::get('collections/{collection}/records/{record}', [ContentController::class, 'show'])->whereNumber('record')->name('records.show');
        Route::put('collections/{collection}/records/{record}', [ContentController::class, 'update'])->whereNumber('record')->name('records.update');
        Route::delete('collections/{collection}/records/{record}', [ContentController::class, 'destroy'])->whereNumber('record')->name('records.destroy');
        Route::get('pages/{slug?}', [ContentController::class, 'page'])->name('pages.show');
    });

    Route::post('public/forms', FormSubmissionController::class)->middleware('throttle:10,1')->name('forms.submit');

    Route::middleware('throttle:120,1')->prefix('public/sites/{site}')->name('site.')->group(function () {
        Route::get('/', [SiteController::class, 'show'])->name('show');
        Route::get('pages/{slug?}', [SiteController::class, 'page'])->name('page');
    });

    // Per IP, on top of the per-account limit inside AuthController.
    // Accepting an invitation to a company (signed in, or creating an account for the invited address).
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    });

    Route::get('site-templates', [AuthController::class, 'templates'])->name('site-templates');

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->name('password.reset');
    });

    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login');
    // Every account gets a company with real tables: creation is rate limited.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('email/verification-notification', [PasswordController::class, 'sendVerification'])->middleware('throttle:3,10')->name('verification.send');

        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Sites saved as templates (owned by the user who saved them).
        Route::get('custom-templates', [CustomTemplateController::class, 'index'])->name('custom-templates.index');
        Route::put('custom-templates/{customTemplate}', [CustomTemplateController::class, 'update'])->name('custom-templates.update');
        Route::delete('custom-templates/{customTemplate}', [CustomTemplateController::class, 'destroy'])->name('custom-templates.destroy');

        Route::post('tenants', [TenantSessionController::class, 'create'])->middleware('throttle:5,1')->name('tenants.create');
        Route::put('tenants/{tenant}/switch', [TenantSessionController::class, 'switch'])->name('tenants.switch');

        Route::middleware('tenant')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::get('company', [CompanyController::class, 'show'])->middleware('can:company.view')->name('company.show');
            Route::put('company', [CompanyController::class, 'update'])->middleware('can:company.update')->name('company.update');

            Route::get('members', [MemberController::class, 'index'])->middleware('can:members.view')->name('members.index');
            Route::middleware('can:members.manage')->group(function () {
                Route::post('members', [MemberController::class, 'store'])->middleware('throttle:20,1')->name('members.store');
                Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
                Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
                Route::post('member-invitations/{invitation}/resend', [MemberController::class, 'resendInvitation'])->middleware('throttle:10,1')->name('member-invitations.resend');
                Route::delete('member-invitations/{invitation}', [MemberController::class, 'revokeInvitation'])->name('member-invitations.destroy');
            });

            Route::get('collections', [CollectionController::class, 'index'])->middleware('can:content.view')->name('collections.index');
            Route::get('collections/{collection}', [CollectionController::class, 'show'])->middleware('can:content.view')->name('collections.show');

            Route::middleware('can:schema.manage')->scopeBindings()->group(function () {
                Route::get('schema/export', [SchemaController::class, 'export'])->name('schema.export');
                Route::post('schema/import', [SchemaController::class, 'import'])->name('schema.import');

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

            Route::get('patterns', [BlockPatternController::class, 'index'])->middleware('can:content.view')->name('patterns.index');
            Route::middleware('can:content.manage')->group(function () {
                Route::post('patterns', [BlockPatternController::class, 'store'])->name('patterns.store');
                Route::put('patterns/{pattern}', [BlockPatternController::class, 'update'])->name('patterns.update');
                Route::delete('patterns/{pattern}', [BlockPatternController::class, 'destroy'])->name('patterns.destroy');
            });

            // Drafts, publishing and revisions of block trees; permissions depend on the target.
            Route::prefix('block-versions')->name('block-versions.')->group(function () {
                Route::get('/', [BlockVersionController::class, 'show'])->name('show');
                Route::put('draft', [BlockVersionController::class, 'saveDraft'])->name('draft');
                Route::delete('draft', [BlockVersionController::class, 'discardDraft'])->name('discard');
                Route::post('publish', [BlockVersionController::class, 'publish'])->name('publish');
                Route::get('{version}', [BlockVersionController::class, 'revision'])->whereNumber('version')->name('revision');
            });

            Route::post('blocks/resolve', [BlockController::class, 'resolve'])->middleware('can:content.view')->name('blocks.resolve');

            Route::prefix('collections/{collection}/records')->name('records.')->whereNumber('record')->group(function () {
                Route::middleware('can:content.view')->group(function () {
                    Route::get('/', [RecordController::class, 'index'])->name('index');
                    Route::get('options', [RecordController::class, 'options'])->name('options');
                    Route::get('{record}', [RecordController::class, 'show'])->name('show');
                    Route::get('{record}/backlinks', [RecordController::class, 'backlinks'])->name('backlinks');
                    Route::get('{record}/revisions', [RecordController::class, 'revisions'])->name('revisions');
                    Route::get('{record}/files/{field}', [RecordController::class, 'file'])->where('field', '[a-z][a-z0-9_]{0,23}')->name('file');
                    Route::get('{record}/translations', [TranslationController::class, 'show'])->name('translations.show');
                });

                Route::middleware('can:content.manage')->group(function () {
                    Route::post('/', [RecordController::class, 'store'])->name('store');
                    Route::put('{record}', [RecordController::class, 'update'])->name('update');
                    Route::delete('{record}', [RecordController::class, 'destroy'])->name('destroy');
                    Route::post('{record}/revisions/{revision}/restore', [RecordController::class, 'restore'])->whereNumber('revision')->name('revisions.restore');
                    Route::put('{record}/translations/{locale}', [TranslationController::class, 'update'])->where('locale', '[a-z]{2}')->name('translations.update');
                });
            });

            Route::middleware('can:api.manage')->group(function () {
                Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
                Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
            });

            // Visitors with accounts on the company site.
            Route::get('site-members', [SiteMemberController::class, 'index'])->middleware('can:content.view')->name('site-members.index');
            Route::middleware('can:content.manage')->group(function () {
                Route::put('site-members/{siteMember}', [SiteMemberController::class, 'update'])->name('site-members.update');
                Route::delete('site-members/{siteMember}', [SiteMemberController::class, 'destroy'])->name('site-members.destroy');
            });

            // Redirects of the company site.
            Route::get('redirects', [RedirectController::class, 'index'])->middleware('can:content.view')->name('redirects.index');
            Route::middleware('can:content.manage')->group(function () {
                Route::post('redirects', [RedirectController::class, 'store'])->name('redirects.store');
                Route::put('redirects/{redirect}', [RedirectController::class, 'update'])->name('redirects.update');
                Route::delete('redirects/{redirect}', [RedirectController::class, 'destroy'])->name('redirects.destroy');
            });

            // Own domains of the company site.
            Route::get('domains', [DomainController::class, 'index'])->middleware('can:company.view')->name('domains.index');
            Route::middleware('can:company.update')->group(function () {
                Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
                Route::post('domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:20,1')->name('domains.verify');
                Route::post('domains/{domain}/primary', [DomainController::class, 'makePrimary'])->name('domains.primary');
                Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
            });

            Route::post('custom-templates', [CustomTemplateController::class, 'store'])->middleware(['can:company.update', 'throttle:5,1'])->name('custom-templates.store');

            Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('can:content.view')->name('site-settings.show');
            Route::put('site-settings', [SiteSettingsController::class, 'update'])->middleware('can:company.update')->name('site-settings.update');

            // Logic without code: webhooks and automations (owners and admins).
            Route::middleware('can:schema.manage')->group(function () {
                Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
                Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
                Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
                Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
                Route::post('webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
                Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
                Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');

                Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
                Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
                Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
                Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
                Route::get('automations/{automation}/runs', [AutomationController::class, 'runs'])->name('automations.runs');
            });

            Route::get('search', AdminSearchController::class)->middleware(['can:content.view', 'throttle:60,1'])->name('admin-search');
            Route::get('site-stats', SiteStatsController::class)->middleware('can:content.view')->name('site-stats');

            Route::get('activity', [ActivityController::class, 'index'])->middleware('can:audit.view')->name('activity.index');

            Route::middleware('super')->prefix('super')->name('super.')->group(function () {
                Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
                Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
                Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
                Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
            });
        });
    });
});

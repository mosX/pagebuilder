<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Events\FormSubmitted;
use App\Events\PagePublished;
use App\Events\RecordCreated;
use App\Events\RecordDeleted;
use App\Events\RecordUpdated;
use App\Listeners\FlushSiteCache;
use App\Listeners\NotifyIntegrations;
use App\Support\Audit\ActivityLog;
use App\Support\Blocks\BlockRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ActivityLog::class);
        $this->app->singleton(BlockRegistry::class, fn () => new BlockRegistry(resource_path('js/blocks')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTenantPermissions();

        // Webhooks and automations react to company events (explicit: the listener method is not "handle").
        Event::listen(
            [RecordCreated::class, RecordUpdated::class, RecordDeleted::class, FormSubmitted::class, PagePublished::class],
            [NotifyIntegrations::class, 'onEvent'],
        );
        Event::listen(
            [RecordCreated::class, RecordUpdated::class, RecordDeleted::class, PagePublished::class],
            [FlushSiteCache::class, 'onEvent'],
        );

        // Headless API: per key (or per IP for requests without a key).
        RateLimiter::for('api-key', fn (Request $request) => Limit::perMinute(120)
            ->by(hash('sha256', $request->bearerToken() ?? (string) $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Each permission becomes a gate checked against the user's role in the current tenant,
     * so routes can use `can:members.manage` and Vue can read the resolved list.
     */
    protected function configureTenantPermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (): bool => (bool) app(TenantContext::class)->role()?->can($permission));
        }
    }
}

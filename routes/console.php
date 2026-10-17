<?php

use App\Models\Activity;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Site\Scheduling;
use App\Support\Site\SiteCache;
use App\Support\Site\SiteStats;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:provision-pages', function (PagesCollection $pages) {
    Tenant::query()->each(function (Tenant $tenant) use ($pages) {
        $pages->ensure($tenant);
        $this->line("Pages ready: {$tenant->name}");
    });
})->purpose('Create the system pages collection for companies that do not have one');

// Scheduled publishing: when content's publication time comes, its company's cached pages
// are dropped, so the content appears without anyone touching the site.
Artisan::command('site:release-scheduled', function (SiteCache $cache) {
    $now = now();
    $since = Carbon::parse((string) Cache::get('site:release-scheduled:last', now()->subMinutes(5)->toIso8601String()));
    $released = [];

    Collection::withoutGlobalScope('tenant')
        ->where(fn ($q) => $q->where('kind', PagesCollection::KIND)->orWhereNotNull('site_publish_at_field'))
        ->with('fields')
        ->each(function (Collection $collection) use ($since, $now, &$released) {
            $name = $collection->kind === PagesCollection::KIND ? Scheduling::PAGES_FIELD : (string) $collection->site_publish_at_field;
            $column = $collection->field($name)?->column();

            $timezone = Scheduling::timezone($collection->tenant_id);

            if ($column && DB::table($collection->table_name)
                ->where($column, '>', $since->copy()->setTimezone($timezone)->toDateTimeString())
                ->where($column, '<=', $now->copy()->setTimezone($timezone)->toDateTimeString())
                ->exists()) {
                $released[$collection->tenant_id] = true;
            }
        });

    foreach (array_keys($released) as $tenantId) {
        $cache->flush($tenantId);
    }

    Cache::forever('site:release-scheduled:last', $now->toIso8601String());
    $this->line('Sites with newly released content: '.count($released));
})->purpose('Refresh cached sites whose scheduled pages or records have just been published');

Schedule::command('site:release-scheduled')->everyMinute()->withoutOverlapping();

// Visit statistics older than App\Support\Site\SiteStats::KEEP_DAYS days.
Schedule::call(fn () => app(SiteStats::class)->prune())->daily()->name('site-stats:prune');

// Activity log entries older than App\Models\Activity::KEEP_DAYS days.
Schedule::command('model:prune', ['--model' => [Activity::class]])->daily();

<?php

use App\Models\Activity;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

// Activity log entries older than App\Models\Activity::KEEP_DAYS days.
Schedule::command('model:prune', ['--model' => [Activity::class]])->daily();

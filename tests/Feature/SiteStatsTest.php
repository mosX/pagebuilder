<?php

use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $pages = app(PagesCollection::class)->ensure($this->tenant);
    (new RecordRepository($pages))->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);
});

function visitSite(string $path, string $agent = 'Mozilla/5.0 Firefox', string $ip = '203.0.113.1', array $headers = []): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => $agent, ...$headers])
        ->get('http://acme.pagebuilder.test'.$path);
}

test('views and daily visitors are counted per page and site', function () {
    visitSite('/');
    visitSite('/');
    visitSite('/about');
    visitSite('/about', ip: '203.0.113.2', headers: ['Referer' => 'https://www.google.com/search?q=acme']);

    $stats = $this->actingAs($this->owner)->getJson(route('api.site-stats', ['days' => 7]))
        ->assertOk()
        ->assertJsonPath('period', 7)
        ->json();

    expect($stats['totals'])->toBe(['views' => 4, 'visitors' => 2])
        ->and(end($stats['days']))->toMatchArray(['views' => 4, 'visitors' => 2])
        ->and($stats['pages'])->toBe([
            ['path' => '/', 'views' => 2, 'visitors' => 1],
            ['path' => '/about', 'views' => 2, 'visitors' => 2],
        ])
        ->and($stats['referrers'])->toBe([['host' => 'google.com', 'views' => 1]]);

    // Only totals are stored: no addresses or browser strings.
    expect(json_encode(DB::table('site_visits')->get()))->not->toContain('203.0.113');
});

test('bots, prefetches, previews, missing pages and links within the site are not counted', function () {
    visitSite('/', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
    visitSite('/', 'curl/8.0');
    visitSite('/', '');
    visitSite('/', headers: ['Sec-Purpose' => 'prefetch']);
    visitSite('/missing');
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get('http://localhost/site/acme');

    expect(DB::table('site_visits')->count())->toBe(0);

    visitSite('/about', headers: ['Referer' => 'http://acme.pagebuilder.test/']);
    expect(DB::table('site_referrers')->count())->toBe(0);
});

test('cached pages are counted too, and statistics stay in their company', function () {
    config(['tenancy.page_cache.enabled' => true]);
    visitSite('/');
    visitSite('/', ip: '203.0.113.9');

    expect((int) DB::table('site_visits')->where('path', '')->value('views'))->toBe(2);

    $other = Tenant::factory()->create();
    $this->actingAs(memberOf($other))->getJson(route('api.site-stats'))
        ->assertOk()
        ->assertJsonPath('totals.views', 0);
});

test('old statistics are pruned', function () {
    visitSite('/');
    $this->travel(401)->days();
    visitSite('/');

    expect(app(SiteStats::class)->prune())->toBe(2)
        ->and(DB::table('site_visits')->count())->toBe(2);
});

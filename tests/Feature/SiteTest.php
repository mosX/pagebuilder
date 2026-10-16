<?php

use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Tenancy\Subdomains;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test', 'tenancy.ssr.enabled' => true]);
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => '<main>SSR</main>'])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
});

function siteUrl(string $path = '/'): string
{
    return 'http://acme.pagebuilder.test'.$path;
}

test('the company site is served on its subdomain with SSR markup and meta tags', function () {
    DB::table($this->pages->table_name)->where('slug', 'home')->update(['description' => 'Best company']);

    $this->get(siteUrl())
        ->assertOk()
        ->assertSee('<title>Главная — Acme</title>', false)
        ->assertSee('<meta name="description" content="Best company">', false)
        ->assertSee('<link rel="canonical" href="https://acme.pagebuilder.test/">', false)
        ->assertSee('<div id="site" data-ssr="1"><main>SSR</main></div>', false)
        ->assertSee('window.__SITE__', false)
        ->assertDontSee('noindex');

    Http::assertSent(fn ($request) => $request['page']['slug'] === 'home' && $request['site']['base_url'] === '');
});

test('pages get their own urls and unknown or unpublished pages are 404', function () {
    $repository = new RecordRepository($this->pages);
    $repository->create(['title' => 'About', 'slug' => 'about', 'is_published' => true]);
    $repository->create(['title' => 'Draft', 'slug' => 'draft', 'is_published' => false]);

    $this->get(siteUrl('/about'))->assertOk()->assertSee('<title>About — Acme</title>', false);
    $this->get(siteUrl('/draft'))->assertNotFound()->assertSee('noindex', false);
    $this->get(siteUrl('/missing'))->assertNotFound();
});

test('a subdomain never exposes the admin app', function () {
    $this->get(siteUrl('/admin/members'))->assertNotFound();
    $this->get(siteUrl('/login/x'))->assertNotFound();
});

test('unknown and inactive companies have no site', function () {
    $this->get('http://nobody.pagebuilder.test/')->assertNotFound();

    $this->tenant->update(['is_active' => false]);
    $this->get(siteUrl())->assertNotFound();
});

test('the preview path on the platform domain is not indexed', function () {
    $this->get('/site/acme')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('<link rel="canonical" href="https://acme.pagebuilder.test/">', false);
});

test('pages still render in the browser when the SSR server is down', function () {
    Cache::forget('site-ssr:down');
    Http::fake(['127.0.0.1:13714/*' => fn () => throw new ConnectionException('down')]);

    $this->get(siteUrl())->assertOk()->assertSee('<div id="site" data-ssr="0"></div>', false);

    // The failure is remembered, so the next visitors do not wait for the timeout.
    expect(Cache::get('site-ssr:down'))->toBeTrue();
    $this->get(siteUrl())->assertOk()->assertSee('data-ssr="0"', false);
});

test('the embedded payload cannot break out of its script tag', function () {
    DB::table($this->pages->table_name)->where('slug', 'home')->update(['title' => '</script><script>alert(1)</script>']);

    $this->get(siteUrl())
        ->assertOk()
        ->assertDontSee('</script><script>alert(1)', false);
});

test('company slugs must be valid, non-reserved subdomains', function (string $slug) {
    $owner = memberOf($this->tenant);

    $this->actingAs($owner)
        ->putJson(route('api.company.update'), ['name' => 'Acme', 'slug' => $slug])
        ->assertJsonValidationErrors('slug');
})->with(['www', 'admin', 'with_underscore', '-dash', 'dash-', 'Upper', 'dot.ted', str_repeat('a', 64)]);

test('generated slugs avoid reserved names and invalid labels', function () {
    expect(Tenant::uniqueSlug('Admin'))->toBe('company-admin')
        ->and(Tenant::uniqueSlug('Рога и копыта'))->toBe('roga-i-kopyta')
        ->and(Tenant::uniqueSlug('!!!'))->toBe('company')
        ->and(Subdomains::isValid(Tenant::uniqueSlug(str_repeat('Long name ', 20))))->toBeTrue();
});

test('the session exposes the company site url', function () {
    $this->actingAs(memberOf($this->tenant))
        ->getJson(route('api.me'))
        ->assertJsonPath('tenant.current.site_url', 'https://acme.pagebuilder.test');
});

test('existing pages collections get new system fields', function () {
    $this->pages->fields()->where('name', 'description')->delete();
    Schema::table($this->pages->table_name, fn ($table) => $table->dropColumn('description'));

    app(PagesCollection::class)->ensure($this->tenant);

    expect($this->pages->fresh()->fields->pluck('name'))->toContain('description');
});

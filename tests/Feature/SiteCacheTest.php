<?php

use App\Models\SiteMember;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteDesign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test', 'tenancy.page_cache.enabled' => true]);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
    $this->about = (new RecordRepository($this->pages))->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);
});

function cachedPage(string $path = '/about'): TestResponse
{
    return test()->get('http://acme.pagebuilder.test'.$path);
}

test('guests get pages from the cache until the company changes something', function () {
    cachedPage()->assertOk()->assertHeader('X-Site-Cache', 'miss');

    // A change behind the app's back is not seen while the page is cached.
    DB::table($this->pages->table_name)->where('id', $this->about['id'])->update(['title' => 'Изменено в базе']);
    cachedPage()->assertOk()->assertHeader('X-Site-Cache', 'hit')->assertSee('О нас — Acme');

    // Any change through the admin drops the company's cached pages.
    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', ['collection' => $this->pages->name, 'record' => $this->about['id']]), ['title' => 'Новый заголовок'])
        ->assertOk();

    cachedPage()->assertOk()->assertHeader('X-Site-Cache', 'miss')->assertSee('Новый заголовок — Acme');
});

test('site settings, publishing and company changes drop the cache', function () {
    cachedPage()->assertHeader('X-Site-Cache', 'miss');
    cachedPage()->assertHeader('X-Site-Cache', 'hit');

    $this->actingAs($this->owner)->putJson(route('api.site-settings.update'), ['theme' => [], 'menu' => []])->assertOk();
    cachedPage()->assertHeader('X-Site-Cache', 'miss');

    $this->actingAs($this->owner)->postJson(route('api.block-versions.publish'), ['target' => 'layout:footer', 'tree' => []])->assertOk();
    cachedPage()->assertHeader('X-Site-Cache', 'miss');

    $this->tenant->update(['is_active' => false]);
    cachedPage()->assertNotFound();
});

test('caches are per page and list page, not per tracking parameter', function () {
    cachedPage('/about?utm_source=mail')->assertHeader('X-Site-Cache', 'miss');
    cachedPage('/about')->assertHeader('X-Site-Cache', 'hit');
    cachedPage('/about?page=2')->assertHeader('X-Site-Cache', 'miss');
    cachedPage('/')->assertHeader('X-Site-Cache', 'miss');
});

test('signed-in visitors, 404s, redirects and service pages are never cached', function () {
    app(SiteDesign::class)->for($this->tenant)->forceFill(['account' => ['enabled' => true]])->save();
    (new RecordRepository($this->pages))->create(['title' => 'Клуб', 'slug' => 'club', 'is_published' => true, 'members_only' => true]);

    cachedPage('/missing')->assertNotFound()->assertHeaderMissing('X-Site-Cache');
    cachedPage('/club')->assertRedirect()->assertHeaderMissing('X-Site-Cache');
    cachedPage('/search?q=нас')->assertOk()->assertHeaderMissing('X-Site-Cache');

    $member = new SiteMember;
    $member->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => 'secret-password'])->save();

    $this->withSession([auth()->guard('member')->getName() => $member->id]);
    cachedPage()->assertOk()->assertHeaderMissing('X-Site-Cache');
});

test('pages carry security headers and a policy without inline scripts', function () {
    // As in production: no Vite dev server.
    Vite::useHotFile(storage_path('framework/testing/no-hot'));

    $response = cachedPage()->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self';")
        ->toContain("object-src 'none'");

    // Page data is a JSON block that cannot break out of its tag.
    (new RecordRepository($this->pages))->update($this->about['id'], ['title' => '</script><script>alert(1)</script>']);
    cachedPage()->assertDontSee('</script><script>alert(1)', false);
});

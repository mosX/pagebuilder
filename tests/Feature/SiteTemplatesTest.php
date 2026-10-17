<?php

use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Site\SiteTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);
});

test('every template builds a working site', function (string $template, array $paths, array $collections) {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    app(SiteTemplates::class)->apply($tenant, $template);

    $names = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->pluck('name')->all();
    expect($names)->toContain(...$collections);

    foreach ($paths as $path) {
        $this->get("http://acme.pagebuilder.test{$path}")->assertOk();
    }
})->with([
    'blank' => ['blank', ['/'], ['pages']],
    'business' => ['business', ['/', '/services', '/about', '/contacts'], ['pages', 'requests']],
    'blog' => ['blog', ['/', '/blog', '/about', '/blog/welcome', '/blog/how-to-edit'], ['pages', 'categories', 'posts']],
    'catalog' => ['catalog', ['/', '/catalog', '/contacts', '/catalog/first', '/catalog/accessory'], ['pages', 'products', 'orders']],
]);

test('a new company is created from the chosen template', function () {
    $this->getJson(route('api.site-templates'))->assertOk()->assertJsonPath('1.key', 'business');

    $this->postJson(route('api.register'), [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'company' => 'Shop',
        'template' => 'catalog',
    ])->assertSuccessful();

    $tenant = User::where('email', 'owner@example.com')->sole()->currentTenant;
    $products = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'products')->sole();

    expect(DB::table($products->table_name)->count())->toBe(3)
        ->and($products->site_path)->toBe('catalog');

    $this->postJson(route('api.tenants.create'), ['name' => 'Other', 'template' => 'unknown'])
        ->assertJsonValidationErrors('template');
});

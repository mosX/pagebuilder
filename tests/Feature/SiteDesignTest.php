<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteDesign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
    (new RecordRepository($this->pages))->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);
});

/**
 * @return array<string, mixed>
 */
function renderedPayload(string $url = 'http://acme.pagebuilder.test/'): array
{
    test()->get($url)->assertOk();
    [$request] = Http::recorded()->last();

    return $request->data();
}

/**
 * The first node of a type anywhere in a tree.
 *
 * @param  array<int, array<string, mixed>>  $nodes
 * @return array<string, mixed>|null
 */
function findNode(array $nodes, string $type): ?array
{
    foreach ($nodes as $node) {
        if ($node['type'] === $type) {
            return $node;
        }

        if ($found = findNode($node['children'] ?? [], $type)) {
            return $found;
        }
    }

    return null;
}

test('theme values are normalized to safe ones', function () {
    $theme = app(SiteDesign::class)->theme([
        'primary' => 'red; background: url(x)',
        'text' => '#ABCDEF',
        'heading_font' => 'Comic Sans',
        'body_font' => 'pt-serif',
        'radius' => 'huge',
    ]);

    expect($theme)->toBe([
        'primary' => '#2563eb',
        'text' => '#abcdef',
        'background' => '#ffffff',
        'heading_font' => 'inter',
        'body_font' => 'pt-serif',
        'radius' => 'medium',
    ]);
});

test('the site is rendered with the theme, fonts, header and footer', function () {
    $this->actingAs($this->owner)->putJson(route('api.site-settings.update'), [
        'theme' => ['primary' => '#ffcc00', 'heading_font' => 'playfair-display', 'radius' => 'large'],
        'menu' => [],
        'logo' => '/storage/tenants/1/logo.png',
    ])->assertOk();

    $response = $this->get('http://acme.pagebuilder.test/')
        ->assertOk()
        ->assertSee('fonts.bunny.net/css?family=playfair-display', false)
        ->assertSee('--site-primary: #ffcc00', false)
        // A light primary color gets dark text on buttons.
        ->assertSee('--site-primary-contrast: #111827', false);

    $payload = renderedPayload();

    expect($payload['theme']['--site-radius'])->toBe('1rem')
        ->and(findNode($payload['layout']['header'], 'site_logo')['data'])->toBe(['name' => 'Acme', 'logo' => '/storage/tenants/1/logo.png', 'url' => '/'])
        ->and(findNode($payload['layout']['footer'], 'site_menu'))->not->toBeNull();
});

test('the menu falls back to published pages and then follows the settings', function () {
    $menu = fn () => findNode(renderedPayload()['layout']['header'], 'site_menu')['data']['items'];

    expect(array_column($menu(), 'url'))->toBe(['/', '/about']);

    $this->actingAs($this->owner)->putJson(route('api.site-settings.update'), [
        'theme' => [],
        'menu' => [
            ['label' => 'О компании', 'type' => 'page', 'page' => 'about', 'children' => [
                ['label' => 'Блог', 'type' => 'url', 'url' => 'https://blog.example'],
                ['label' => 'Злой', 'type' => 'url', 'url' => 'javascript:alert(1)'],
            ]],
            ['label' => '', 'type' => 'page', 'page' => 'home'],
        ],
    ])->assertOk();

    $items = $menu();

    expect($items)->toHaveCount(1)
        ->and($items[0]['url'])->toBe('/about')
        ->and($items[0]['slug'])->toBe('about')
        ->and(array_column($items[0]['children'], 'url'))->toBe(['https://blog.example', '#']);

    // On the preview path page links stay inside the preview.
    $preview = findNode(renderedPayload('http://localhost/site/acme')['layout']['header'], 'site_menu')['data']['items'];
    expect($preview[0]['url'])->toBe('/site/acme/about');
});

test('only owners and admins change the design; editors edit header and footer', function () {
    $editor = memberOf($this->tenant, TenantRole::Editor);

    $this->actingAs($editor)->getJson(route('api.site-settings.show'))->assertOk()->assertJsonPath('theme.radius', 'medium');
    $this->actingAs($editor)->putJson(route('api.site-settings.update'), ['theme' => [], 'menu' => []])->assertForbidden();

    $this->actingAs($editor)->postJson(route('api.block-versions.publish'), [
        'target' => 'layout:header',
        'tree' => [['type' => 'heading', 'props' => ['text' => 'Своя шапка']]],
    ])->assertOk();

    expect(renderedPayload()['layout']['header'][0]['props']['text'])->toBe('Своя шапка');

    $this->actingAs($editor)->postJson(route('api.block-versions.publish'), ['target' => 'layout:sidebar', 'tree' => []])
        ->assertJsonValidationErrors('target');
});

test('list props keep declared item fields and respect limits', function () {
    $items = array_fill(0, 40, ['question' => 'Q', 'answer' => 'A', 'evil' => '<script>', 'items' => [['x' => 1]]]);

    $tree = app(BlockRegistry::class)->sanitize([
        ['type' => 'faq', 'props' => ['items' => $items]],
        ['type' => 'pricing', 'props' => ['items' => [['name' => 'Pro', 'button_url' => 'javascript:alert(1)', 'highlighted' => true]]]],
    ], 'content');

    $faq = ((array) $tree[0]['props'])['items'];
    $plan = ((array) $tree[1]['props'])['items'][0];

    expect($faq)->toHaveCount(30)
        ->and($faq[0])->toBe(['question' => 'Q', 'answer' => 'A'])
        ->and($plan['button_url'])->toBe('')
        ->and($plan['highlighted'])->toBeTrue()
        ->and($plan['price'])->toBe('1000 ₴');
});

test('resized images reject unknown widths and unsafe paths', function () {
    Storage::fake('public');
    Storage::disk('public')->put('tenants/1/photo.png', 'x');

    $this->get('/img/500/tenants/1/photo.png')->assertNotFound();
    $this->get('/img/640/tenants/1/../../.env')->assertNotFound();
    $this->get('/img/640/other/photo.png')->assertNotFound();
    $this->get('/img/640/tenants/1/missing.png')->assertNotFound();
    $this->get('/img/640/tenants/1/photo.svg')->assertNotFound();
});

test('without GD the original image is served', function () {
    if (extension_loaded('gd')) {
        $this->markTestSkipped('GD is available here; the resize test covers it.');
    }

    Storage::fake('public');
    Storage::disk('public')->put('tenants/1/photo.png', 'x');

    $this->get('/img/640/tenants/1/photo.png')->assertRedirect('/storage/tenants/1/photo.png');
});

test('with GD a resized copy is written next to the public files', function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD is not available in this PHP.');
    }

    Storage::fake('public');
    $image = UploadedFile::fake()->image('wide.jpg', 1600, 800);
    Storage::disk('public')->putFileAs('tenants/1', $image, 'wide.jpg');

    $this->get('/img/640/tenants/1/wide.jpg')->assertOk();

    $copy = public_path('img/640/tenants/1/wide.jpg');
    expect(getimagesize($copy)[0])->toBe(640);

    File::deleteDirectory(public_path('img/640/tenants/1'));
});

<?php

use App\Enums\FieldType;
use App\Http\Controllers\ImageController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Automation\UrlGuard;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\Forms;
use Illuminate\Support\Facades\Hash;

test('ipv6 addresses that carry an internal ipv4 address are not public', function (string $ip, bool $public) {
    expect(app(UrlGuard::class)->isPublic($ip))->toBe($public);
})->with([
    ['8.8.8.8', true],
    ['2606:4700:4700::1111', true],
    ['127.0.0.1', false],
    ['10.0.0.5', false],
    ['100.64.1.1', false],
    ['::1', false],
    ['::ffff:127.0.0.1', false],
    ['::ffff:7f00:1', false],
    ['::ffff:8.8.8.8', true],
    ['::7f00:1', false],
    ['64:ff9b::7f00:1', false],
    ['2002:7f00:1::', false],
    ['2001:0:4136:e378::1', false],
    ['not-an-ip', false],
]);

test('links that browsers read as another host are dropped', function () {
    $registry = app(BlockRegistry::class);

    expect($registry->safeUrl('/\\evil.com'))->toBe('')
        ->and($registry->safeUrl('//evil.com'))->toBe('')
        ->and($registry->safeUrl('/about'))->toBe('/about');
});

test('changing the sign-in e-mail needs the current password', function () {
    $tenant = Tenant::factory()->create();
    $user = memberOf($tenant);
    $user->forceFill(['password' => Hash::make('old-password')])->save();

    $this->actingAs($user)->putJson(route('api.profile.update'), ['name' => 'New name', 'email' => $user->email])
        ->assertOk();

    $this->actingAs($user)->putJson(route('api.profile.update'), ['name' => 'X', 'email' => 'new@example.com'])
        ->assertJsonValidationErrors('current_password');
    $this->actingAs($user)->putJson(route('api.profile.update'), ['name' => 'X', 'email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertJsonValidationErrors('current_password');
    $this->actingAs($user)->putJson(route('api.profile.update'), ['name' => 'X', 'email' => 'new@example.com', 'current_password' => 'old-password'])
        ->assertOk();

    expect(User::find($user->id)->email)->toBe('new@example.com');
});

test('pages for members are not listed in the public site api', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $pages = app(PagesCollection::class)->ensure($tenant);
    (new RecordRepository($pages))->create(['title' => 'Клуб', 'slug' => 'club', 'is_published' => true, 'members_only' => true]);

    $this->getJson('/api/public/sites/acme')
        ->assertOk()
        ->assertJsonPath('pages.0.slug', 'home')
        ->assertJsonCount(1, 'pages');
});

test('form tokens expire', function () {
    $tenant = Tenant::factory()->create();
    $schema = app(SchemaManager::class);
    $leads = $schema->createCollection($tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($leads, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String]);
    $token = app(Forms::class)->schema($tenant, $leads->fresh(), ['name'], withToken: true)['token'];

    $this->postJson(route('api.forms.submit'), ['token' => $token, 'values' => ['name' => 'Ivan']])->assertCreated();

    $this->travel(Forms::TOKEN_TTL + 60)->seconds();
    $this->postJson(route('api.forms.submit'), ['token' => $token, 'values' => ['name' => 'Ivan']])
        ->assertJsonValidationErrors('form');
});

test('without the preview path, /site/{slug} leads to the real site', function () {
    config(['tenancy.site_domain' => 'pagebuilder.test', 'tenancy.preview_path' => false]);
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    app(PagesCollection::class)->ensure($tenant);

    $this->get('http://localhost/site/acme')->assertRedirect('https://acme.pagebuilder.test/')->assertStatus(301);
    $this->get('http://localhost/site/acme/en/about?page=2')->assertRedirect('https://acme.pagebuilder.test/en/about?page=2');
});

test('a user owns a limited number of companies', function () {
    config(['tenancy.max_owned_companies' => 2]);
    $user = memberOf(Tenant::factory()->create());

    $this->actingAs($user)->postJson(route('api.tenants.create'), ['name' => 'Second'])->assertSuccessful();
    $this->actingAs($user)->postJson(route('api.tenants.create'), ['name' => 'Third'])->assertJsonValidationErrors('name');

    // Platform super admins are not limited.
    $user->forceFill(['is_super_admin' => true])->save();
    $this->actingAs($user)->postJson(route('api.tenants.create'), ['name' => 'Third'])->assertSuccessful();
});

test('images are resized only when the decode fits into the memory limit', function () {
    expect(ImageController::fitsInMemory(2000, 1500, 640, '-1'))->toBeTrue()
        ->and(ImageController::fitsInMemory(2000, 1500, 640, '1G'))->toBeTrue()
        // 40 megapixels need about 300 MB.
        ->and(ImageController::fitsInMemory(8000, 5000, 640, '256M'))->toBeFalse()
        ->and(ImageController::fitsInMemory(8000, 5000, 640, '512M'))->toBeTrue();
});

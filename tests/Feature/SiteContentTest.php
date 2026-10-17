<?php

use App\Enums\FieldType;
use App\Mail\SiteMemberVerificationMail;
use App\Models\SiteMember;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\SiteDesign;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
    $this->repository = new RecordRepository($this->pages);
});

function acme(string $path = '/'): string
{
    return 'http://acme.pagebuilder.test'.$path;
}

/**
 * The SSR payload of the last rendered site page.
 *
 * @return array<string, mixed>
 */
function lastPayload(): array
{
    [$request] = Http::recorded()->last();

    // As the SSR server receives it: objects become arrays.
    return json_decode((string) json_encode($request->data()), true);
}

/**
 * A text block with a paragraph.
 *
 * @return array<string, mixed>
 */
function paragraph(string $text): array
{
    return ['type' => 'text', 'props' => ['text' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
    ]]]];
}

/**
 * @param  array<string, mixed>  $values
 */
function siteSettings(array $values): void
{
    app(SiteDesign::class)->for(test()->tenant)->forceFill($values)->save();
}

function enableAccounts(array $account = []): void
{
    siteSettings(['account' => ['enabled' => true, 'registration' => true, ...$account]]);
}

function siteMember(array $attributes = []): SiteMember
{
    $member = new SiteMember;
    $member->forceFill([
        'tenant_id' => test()->tenant->id,
        'name' => 'Ivan',
        'email' => 'ivan@example.com',
        'password' => 'secret-password',
        ...$attributes,
    ])->save();

    return $member;
}

// --- Search ---

test('search finds published pages by the text of their blocks', function () {
    $this->repository->create(['title' => 'Доставка', 'slug' => 'delivery', 'is_published' => true, 'content' => [paragraph('Привозим заказы курьером за один день.')]]);
    $this->repository->create(['title' => 'Черновик', 'slug' => 'draft', 'is_published' => false, 'content' => [paragraph('Курьером тоже')]]);
    $this->repository->create(['title' => 'Скидки', 'slug' => 'vip', 'is_published' => true, 'members_only' => true, 'content' => [paragraph('Курьером бесплатно')]]);

    $this->get(acme('/search?q=курьер'))->assertOk()->assertSee('noindex', false);
    $results = lastPayload()['page']['blocks'][0]['data']['results'];

    // Unpublished pages and pages for signed-in visitors are not found.
    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Доставка')
        ->and($results[0]['url'])->toBe('/delivery')
        ->and($results[0]['excerpt'])->toContain('курьером');
});

test('search covers record sections and ignores too short queries', function () {
    $schema = app(SchemaManager::class);
    $articles = $schema->createCollection($this->tenant, ['name' => 'articles', 'label' => 'Статьи']);
    $schema->addField($articles, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String]);
    $schema->addField($articles, ['name' => 'body', 'label' => 'Текст', 'type' => FieldType::Text]);
    $schema->addField($articles, ['name' => 'published', 'label' => 'Опубликована', 'type' => FieldType::Boolean]);
    $articles->forceFill(['site_path' => 'blog', 'site_slug_field' => 'id', 'site_published_field' => 'published', 'display_field' => 'title'])->save();

    $records = new RecordRepository($articles->fresh());
    $shown = $records->create(['title' => 'Как выбрать велосипед', 'body' => 'Рама, колёса и тормоза.', 'published' => true]);
    $records->create(['title' => 'Велосипед-черновик', 'body' => '', 'published' => false]);

    $this->get('http://localhost/site/acme/search?q=велосипед')->assertOk();
    $results = lastPayload()['page']['blocks'][0]['data']['results'];

    expect($results)->toHaveCount(1)
        ->and($results[0]['url'])->toBe("/site/acme/blog/{$shown['id']}");

    $this->get(acme('/search?q=в'))->assertOk();
    expect(lastPayload()['page']['blocks'][0]['data']['results'])->toBe([]);
});

test('service blocks cannot be saved into pages', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => 'layout:header', 'tree' => [['type' => 'account', 'props' => []]]])
        ->assertUnprocessable();
});

// --- Visitor accounts ---

test('the account area does not exist until the company turns it on', function () {
    $this->get(acme('/account/login'))->assertNotFound();
    $this->post(acme('/account/register'), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertNotFound();
});

test('visitors sign up, confirm the e-mail and see their own submissions', function () {
    Mail::fake();
    $schema = app(SchemaManager::class);
    $leads = $schema->createCollection($this->tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($leads, ['name' => 'subject', 'label' => 'Тема', 'type' => FieldType::String]);
    $schema->addField($leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);
    $leads->forceFill(['display_field' => 'subject'])->save();
    $leadRecords = new RecordRepository($leads->fresh());
    $leadRecords->create(['subject' => 'Моя заявка', 'email' => 'Ivan@Example.com']);
    $leadRecords->create(['subject' => 'Чужая заявка', 'email' => 'other@example.com']);

    enableAccounts(['collections' => [['collection' => 'leads', 'email_field' => 'email']]]);

    $this->post(acme('/account/register'), [
        'name' => 'Ivan',
        'email' => 'IVAN@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(acme('/account'));

    $member = SiteMember::withoutGlobalScope('tenant')->sole();
    expect($member->email)->toBe('ivan@example.com')
        ->and($member->tenant_id)->toBe($this->tenant->id)
        ->and(Auth::guard('member')->id())->toBe($member->id);

    // Not confirmed yet: no submissions are shown.
    $this->get(acme('/account'))->assertOk();
    $data = lastPayload()['page']['blocks'][0]['data'];
    expect($data['view'])->toBe('cabinet')
        ->and($data['member']['verified'])->toBeFalse()
        ->and($data['submissions'])->toBe([])
        ->and(lastPayload()['site']['account']['member']['name'])->toBe('Ivan');

    $url = null;
    Mail::assertSent(SiteMemberVerificationMail::class, function (SiteMemberVerificationMail $mail) use (&$url) {
        $url = $mail->url;

        return $mail->hasTo('ivan@example.com');
    });

    // The link leads to the canonical (https) address of the site.
    $this->get($url)->assertRedirect('https://acme.pagebuilder.test/account');
    expect($member->fresh()->email_verified_at)->not->toBeNull();

    // The guard keeps its instance between test requests; a real request loads it again.
    Auth::guard('member')->user()?->refresh();

    $this->get(acme('/account'))->assertOk();
    $submissions = lastPayload()['page']['blocks'][0]['data']['submissions'];
    expect($submissions)->toHaveCount(1)
        ->and(array_column($submissions[0]['items'], 'title'))->toBe(['Моя заявка']);
});

test('a tampered verification link is rejected', function () {
    enableAccounts();
    $member = siteMember();

    $this->get(acme("/account/verify/{$member->id}/".sha1('ivan@example.com')))->assertForbidden();
    expect($member->fresh()->email_verified_at)->toBeNull();
});

test('sign-in checks the password, blocks and throttles', function () {
    enableAccounts();
    $member = siteMember();

    $this->from(acme('/account/login'))
        ->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'wrong'])
        ->assertRedirect(acme('/account/login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest('member');

    // Errors come back into the form (the browser sends its session cookie).
    $this->withCookie(session()->getName(), session()->getId())->get(acme('/account/login'))->assertOk();
    expect(lastPayload()['page']['blocks'][0]['data']['errors']['email'])->toBe('Неверный e-mail или пароль.');

    $member->forceFill(['is_blocked' => true])->save();
    $this->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'secret-password'])->assertSessionHasErrors('email');
    $this->assertGuest('member');

    $member->forceFill(['is_blocked' => false])->save();
    foreach (range(1, 5) as $ignored) {
        $this->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'wrong']);
    }
    $this->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'secret-password'])
        ->assertSessionHasErrors('email');
    // The wait depends on the clock (59 or 60 s), the reason does not.
    expect(session('errors')->first('email'))->toStartWith('Слишком много попыток входа.');
});

test('sign-in follows only redirects within the site', function () {
    enableAccounts();
    siteMember();

    $this->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'secret-password', 'redirect' => '//evil.com/x'])
        ->assertRedirect(acme('/account'));

    Auth::guard('member')->logout();

    $this->post(acme('/account/login'), ['email' => 'ivan@example.com', 'password' => 'secret-password', 'redirect' => '/club'])
        ->assertRedirect(acme('/club'));
});

test('pages for members send guests to sign in', function () {
    $this->repository->create(['title' => 'Клуб', 'slug' => 'club', 'is_published' => true, 'members_only' => true]);

    // Without visitor accounts such pages are simply not available.
    $this->get(acme('/club'))->assertNotFound();
    $this->getJson('/api/public/sites/acme/pages/club')->assertNotFound();

    enableAccounts();
    $this->get(acme('/club'))->assertRedirect(acme('/account/login?redirect=%2Fclub'));

    $this->actingAs(siteMember(), 'member');
    $this->get(acme('/club'))->assertOk();
    expect(lastPayload()['page']['title'])->toBe('Клуб');
});

test('a visitor of one company is a guest on another company site', function () {
    $other = Tenant::factory()->create(['slug' => 'beta']);
    app(PagesCollection::class)->ensure($other);
    app(SiteDesign::class)->for($other)->forceFill(['account' => ['enabled' => true]])->save();
    enableAccounts();

    $this->actingAs(siteMember(), 'member');

    $this->get('http://localhost/site/beta/account')->assertRedirect('http://localhost/site/beta/account/login');
    $this->get('http://localhost/site/acme/account')->assertOk();
});

test('blocking a visitor ends their session', function () {
    enableAccounts();
    $member = siteMember();
    $this->actingAs($member, 'member')->get(acme('/account'))->assertOk();

    $this->actingAs($this->owner, 'web')
        ->putJson(route('api.site-members.update', $member), ['is_blocked' => true])
        ->assertOk();

    // The guard keeps this instance between test requests; a real request loads it again.
    $member->refresh();
    $this->get(acme('/account'))->assertRedirect(acme('/account/login'));
    $this->assertGuest('member');
});

test('admins list, block and delete only their own visitors', function () {
    $mine = siteMember();
    $other = Tenant::factory()->create();
    $foreign = new SiteMember;
    $foreign->forceFill(['tenant_id' => $other->id, 'name' => 'Foreign', 'email' => 'f@example.com', 'password' => 'x'])->save();

    $this->actingAs($this->owner)->getJson(route('api.site-members.index', ['search' => 'ivan']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.email', 'ivan@example.com')
        ->assertJsonMissingPath('data.0.password');

    $this->actingAs($this->owner)->deleteJson(route('api.site-members.destroy', $foreign->id))->assertNotFound();
    $this->actingAs($this->owner)->deleteJson(route('api.site-members.destroy', $mine))->assertOk();

    expect(SiteMember::withoutGlobalScope('tenant')->pluck('email')->all())->toBe(['f@example.com']);
});

test('account settings keep only collections with an e-mail field of the company', function () {
    $schema = app(SchemaManager::class);
    $leads = $schema->createCollection($this->tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);
    $schema->addField($leads, ['name' => 'count', 'label' => 'Кол-во', 'type' => FieldType::Integer]);

    $this->actingAs($this->owner)->putJson(route('api.site-settings.update'), [
        'theme' => [],
        'menu' => [],
        'languages' => ['default' => 'ru', 'extra' => ['en', 'xx', 'ru']],
        'account' => ['enabled' => true, 'registration' => false, 'collections' => [
            ['collection' => 'leads', 'email_field' => 'email'],
            ['collection' => 'leads', 'email_field' => 'count'],
            ['collection' => 'missing', 'email_field' => 'email'],
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('languages', ['default' => 'ru', 'extra' => ['en']])
        ->assertJsonPath('account.registration', false)
        ->assertJsonPath('account.collections', [['collection' => 'leads', 'email_field' => 'email', 'status_field' => null, 'notify' => false]]);

    // Sign-up is closed, sign-in is not.
    $this->get(acme('/account/register'))->assertNotFound();
    $this->get(acme('/account/login'))->assertOk();
});

// --- Languages ---

test('pages are served in additional languages with fallbacks, menus and alternates', function () {
    siteSettings([
        'languages' => ['default' => 'ru', 'extra' => ['en']],
        'menu' => [['label' => 'О нас', 'labels' => ['en' => 'About us'], 'type' => 'page', 'page' => 'about']],
    ]);
    $about = $this->repository->create(['title' => 'О нас', 'slug' => 'about', 'description' => 'Описание', 'is_published' => true, 'content' => [paragraph('Привет')]]);

    $this->actingAs($this->owner)
        ->putJson(route('api.records.translations.update', ['collection' => $this->pages->name, 'record' => $about['id'], 'locale' => 'en']), [
            'values' => ['title' => 'About', 'slug' => 'hacked', 'content' => [paragraph('Hello')]],
        ])
        ->assertOk()
        ->assertJsonMissingPath('values.slug');

    $this->get(acme('/en/about'))
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('<link rel="canonical" href="https://acme.pagebuilder.test/en/about">', false)
        ->assertSee('<link rel="alternate" hreflang="ru" href="https://acme.pagebuilder.test/about">', false)
        ->assertSee('<link rel="alternate" hreflang="en" href="https://acme.pagebuilder.test/en/about">', false);

    $payload = lastPayload();
    $menu = $payload['layout']['header'][0]['children'][0]['children'][1]['data']['items'];

    // Untranslated fields fall back to the default language.
    expect($payload['page']['title'])->toBe('About')
        ->and($payload['page']['description'])->toBe('Описание')
        ->and($payload['page']['blocks'][0]['props']['text']['content'][0]['content'][0]['text'])->toBe('Hello')
        ->and($payload['site']['base_url'])->toBe('/en')
        ->and($payload['site']['search_url'])->toBe('/en/search')
        ->and($payload['site']['languages'])->toBe([
            ['code' => 'ru', 'label' => 'Русский', 'url' => '/about', 'active' => false],
            ['code' => 'en', 'label' => 'English', 'url' => '/en/about', 'active' => true],
        ])
        ->and($menu[0])->toMatchArray(['label' => 'About us', 'url' => '/en/about']);

    // The default language is unchanged.
    $this->get(acme('/about'))->assertOk()->assertSee('<html lang="ru">', false);
    expect(lastPayload()['page']['title'])->toBe('О нас');

    // Search works in the visitor's language.
    $this->get(acme('/en/search?q=hello'))->assertOk();
    expect(lastPayload()['page']['blocks'][0]['data']['results'][0]['url'])->toBe('/en/about');
});

test('a language that is not enabled is a regular path', function () {
    $this->repository->create(['title' => 'Deutschland', 'slug' => 'de', 'is_published' => true]);

    $this->get(acme('/de'))->assertOk();
    expect(lastPayload()['page']['title'])->toBe('Deutschland');

    $this->get(acme('/en/about'))->assertNotFound();
});

test('blocks fields are translated in the constructor with a language suffix', function () {
    $about = $this->repository->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true, 'content' => [paragraph('Привет')]]);
    $target = "field:{$this->pages->name}:{$about['id']}:content";

    // A new translation starts as a copy of the original.
    $this->actingAs($this->owner)->getJson(route('api.block-versions.show', ['target' => "{$target}@en"]))
        ->assertOk()
        ->assertJsonPath('published.0.props.text.content.0.content.0.text', 'Привет');

    $this->actingAs($this->owner)->postJson(route('api.block-versions.publish'), ['target' => "{$target}@en", 'tree' => [paragraph('Hello')]])
        ->assertOk();

    expect($this->repository->find($about['id'])['content'][0]['props']['text']['content'][0]['content'][0]['text'])->toBe('Привет');

    $this->actingAs($this->owner)->getJson(route('api.records.translations.show', ['collection' => $this->pages->name, 'record' => $about['id']]))
        ->assertOk()
        ->assertJsonPath('translations.en.content.0.props.text.content.0.content.0.text', 'Hello')
        ->assertJsonPath('fields.0.name', 'title');

    $this->actingAs($this->owner)->getJson(route('api.block-versions.show', ['target' => "{$target}@xx"]))->assertUnprocessable();
});

test('collection section paths cannot take service or language paths', function () {
    $collection = app(SchemaManager::class)->createCollection($this->tenant, ['name' => 'news', 'label' => 'Новости']);

    foreach (['search', 'account', 'en'] as $path) {
        $this->actingAs($this->owner)
            ->putJson(route('api.collections.site', $collection), ['site_path' => $path, 'site_slug_field' => 'id'])
            ->assertJsonValidationErrors('site_path');
    }
});

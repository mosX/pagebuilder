<?php

use App\Enums\FieldType;
use App\Models\RecordRevision;
use App\Models\Redirect;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\SiteDesign;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
});

function site(string $path): string
{
    return 'http://acme.pagebuilder.test'.$path;
}

// --- Redirects ---

test('a page that changed its address redirects from the old one, without chains', function () {
    $repository = new RecordRepository($this->pages);
    $page = $repository->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);

    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => $this->pages->name, 'record' => $page['id']]), ['slug' => 'company'])->assertOk();
    $this->get(site('/about?utm_source=mail'))->assertRedirect(site('/company?utm_source=mail'))->assertStatus(301);

    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => $this->pages->name, 'record' => $page['id']]), ['slug' => 'team'])->assertOk();
    // Both old addresses lead straight to the current one.
    $this->get(site('/about'))->assertRedirect(site('/team'));
    $this->get(site('/company'))->assertRedirect(site('/team'));

    // Moving back removes the redirect from the address the page lives at again.
    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => $this->pages->name, 'record' => $page['id']]), ['slug' => 'about'])->assertOk();
    $this->get(site('/about'))->assertOk();
    $this->get(site('/team'))->assertRedirect(site('/about'));

    expect(Redirect::query()->where('from_path', '/about')->exists())->toBeFalse();
});

test('record pages and whole sections redirect when their addresses change', function () {
    $schema = app(SchemaManager::class);
    $posts = $schema->createCollection($this->tenant, ['name' => 'posts', 'label' => 'Статьи']);
    $schema->addField($posts, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String]);
    $schema->addField($posts, ['name' => 'slug', 'label' => 'Адрес', 'type' => FieldType::String, 'is_unique' => true]);
    $posts->forceFill(['site_path' => 'blog', 'site_slug_field' => 'slug'])->save();
    $post = (new RecordRepository($posts->fresh()))->create(['title' => 'Первая', 'slug' => 'first']);

    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => 'posts', 'record' => $post['id']]), ['slug' => 'hello'])->assertOk();
    $this->get(site('/blog/first'))->assertRedirect(site('/blog/hello'));

    $this->actingAs($this->owner)->putJson(route('api.collections.site', 'posts'), ['site_path' => 'news', 'site_slug_field' => 'slug'])->assertOk();
    $this->get(site('/blog/hello'))->assertRedirect(site('/news/hello'));
    $this->get(site('/news/hello'))->assertOk();
});

test('redirects added by hand cover old urls of any shape and never hide live pages', function () {
    (new RecordRepository($this->pages))->create(['title' => 'Контакты', 'slug' => 'contacts', 'is_published' => true]);

    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => 'https://old.example/2019/05/Contacts.html?x=1', 'to_path' => '/contacts'])
        ->assertCreated()
        ->assertJsonPath('redirect.from_path', '/2019/05/Contacts.html');
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/wp-content/*', 'to_path' => 'https://cdn.example.com/*', 'status' => 302])->assertCreated();
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/contacts', 'to_path' => '/elsewhere'])->assertCreated();

    $this->get(site('/2019/05/Contacts.html'))->assertRedirect(site('/contacts'));
    $this->get(site('/wp-content/uploads/a.jpg'))->assertRedirect('https://cdn.example.com/uploads/a.jpg')->assertStatus(302);
    $this->get(site('/contacts'))->assertOk();
    $this->get(site('/nothing-here'))->assertNotFound();

    expect(Redirect::query()->where('from_path', '/2019/05/Contacts.html')->value('hits'))->toBe(1);

    // Unsafe targets and duplicates are refused.
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/x', 'to_path' => 'javascript:alert(1)'])->assertJsonValidationErrors('to_path');
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/x', 'to_path' => '//evil.com'])->assertJsonValidationErrors('to_path');
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/contacts', 'to_path' => '/a'])->assertJsonValidationErrors('from_path');
});

test('the site language prefix is kept when following a redirect', function () {
    app(SiteDesign::class)->for($this->tenant)->forceFill(['languages' => ['default' => 'ru', 'extra' => ['en']]])->save();
    (new RecordRepository($this->pages))->create(['title' => 'Новое', 'slug' => 'new', 'is_published' => true]);
    $this->actingAs($this->owner)->postJson(route('api.redirects.store'), ['from_path' => '/old', 'to_path' => '/new'])->assertCreated();

    $this->get(site('/en/old'))->assertRedirect(site('/en/new'));
});

// --- History ---

test('every change of a record is kept and can be rolled back', function () {
    $schema = app(SchemaManager::class);
    $products = $schema->createCollection($this->tenant, ['name' => 'products', 'label' => 'Товары']);
    $schema->addField($products, ['name' => 'title', 'label' => 'Название', 'type' => FieldType::String]);
    $schema->addField($products, ['name' => 'price', 'label' => 'Цена', 'type' => FieldType::Integer]);
    $categories = $schema->createCollection($this->tenant, ['name' => 'categories', 'label' => 'Категории']);
    $schema->addField($categories, ['name' => 'title', 'label' => 'Название', 'type' => FieldType::String]);
    $schema->addField($products, ['name' => 'category', 'label' => 'Категория', 'type' => FieldType::BelongsTo, 'related_collection_id' => $categories->id]);
    $tea = (new RecordRepository($categories->fresh()))->create(['title' => 'Чай']);
    $coffee = (new RecordRepository($categories->fresh()))->create(['title' => 'Кофе']);

    $product = (new RecordRepository($products->fresh()))->create(['title' => 'Улун', 'price' => 500, 'category' => $tea['id']]);
    $url = fn (string $name, array $extra = []) => route($name, ['collection' => 'products', 'record' => $product['id'], ...$extra]);

    $this->actingAs($this->owner)->putJson($url('api.records.update'), ['price' => 5000, 'category' => $coffee['id']])->assertOk();

    $revisions = $this->actingAs($this->owner)->getJson($url('api.records.revisions'))
        ->assertOk()
        ->assertJsonCount(2, 'revisions')
        ->assertJsonPath('revisions.0.changes', ['price', 'category'])
        ->assertJsonPath('revisions.0.author', $this->owner->name)
        ->assertJsonPath('revisions.1.author', 'Сайт')
        ->json('revisions');

    // The mistaken price goes back, relations included.
    $this->actingAs($this->owner)->postJson($url('api.records.revisions.restore', ['revision' => $revisions[1]['id']]))->assertOk();
    $restored = (new RecordRepository($products->fresh()))->find($product['id']);
    expect($restored['price'])->toBe(500)
        ->and($restored['category']['label'])->toBe('Чай');

    // The restore is a version too, so it can be undone.
    $this->actingAs($this->owner)->getJson($url('api.records.revisions'))->assertJsonCount(3, 'revisions');

    // Another record's version cannot be applied here.
    $other = (new RecordRepository($products->fresh()))->create(['title' => 'Пуэр']);
    $foreign = RecordRevision::query()->where('collection_id', $products->id)->where('record_id', $other['id'])->sole();
    $this->actingAs($this->owner)->postJson($url('api.records.revisions.restore', ['revision' => $foreign->id]))->assertNotFound();

    // Deleting the record deletes its history.
    $this->actingAs($this->owner)->deleteJson($url('api.records.destroy'))->assertOk();
    expect(RecordRevision::query()->where('collection_id', $products->id)->where('record_id', $product['id'])->count())->toBe(0);
});

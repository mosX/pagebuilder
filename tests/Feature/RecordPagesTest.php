<?php

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);

    $schema = app(SchemaManager::class);
    $this->authors = $schema->createCollection($this->tenant, ['name' => 'authors', 'label' => 'Authors']);
    $schema->addField($this->authors, ['name' => 'name', 'label' => 'Name', 'type' => FieldType::String]);

    $this->articles = $schema->createCollection($this->tenant, ['name' => 'articles', 'label' => 'Articles']);
    $schema->addField($this->articles, ['name' => 'title', 'label' => 'Title', 'type' => FieldType::String]);
    $schema->addField($this->articles, ['name' => 'slug', 'label' => 'Slug', 'type' => FieldType::String, 'is_unique' => true]);
    $schema->addField($this->articles, ['name' => 'body', 'label' => 'Body', 'type' => FieldType::Text]);
    $schema->addField($this->articles, ['name' => 'cover', 'label' => 'Cover', 'type' => FieldType::Image]);
    $schema->addField($this->articles, ['name' => 'kind', 'label' => 'Kind', 'type' => FieldType::Select, 'options' => ['choices' => ['news', 'guide']]]);
    $schema->addField($this->articles, ['name' => 'published', 'label' => 'Published', 'type' => FieldType::Boolean]);
    $schema->addField($this->articles, ['name' => 'author', 'label' => 'Author', 'type' => FieldType::BelongsTo, 'related_collection_id' => $this->authors->id]);

    $ann = (new RecordRepository($this->authors->fresh()))->create(['name' => 'Ann']);
    $repository = new RecordRepository($this->articles->fresh());
    $repository->create(['title' => 'Hello', 'slug' => 'hello', 'body' => 'First post body', 'cover' => '/storage/a.png', 'kind' => 'news', 'published' => true, 'author' => $ann['id']]);
    $repository->create(['title' => 'Draft', 'slug' => 'draft', 'kind' => 'news', 'published' => false]);
    $repository->create(['title' => 'Guide', 'slug' => 'guide', 'kind' => 'guide', 'published' => true]);
});

function publishArticles(array $template = []): void
{
    $articles = test()->articles;

    test()->actingAs(test()->owner)
        ->putJson(route('api.collections.site', $articles), [
            'site_path' => 'articles', 'site_slug_field' => 'slug', 'site_published_field' => 'published',
        ])->assertOk()->assertJsonPath('collection.site.url', 'https://acme.pagebuilder.test/articles');

    test()->actingAs(test()->owner)
        ->putJson(route('api.collections.template', $articles), ['template' => $template ?: [
            ['type' => 'record_field', 'props' => ['field' => 'title', 'style' => 'h1']],
            ['type' => 'record_field', 'props' => ['field' => 'cover', 'style' => 'image']],
            ['type' => 'record_field', 'props' => ['field' => 'author', 'style' => 'tags']],
        ]])->assertOk();
}

test('published records get pages rendered with the collection template', function () {
    publishArticles();

    $response = $this->get('http://acme.pagebuilder.test/articles/hello')
        ->assertOk()
        ->assertSee('<title>Hello — '.$this->tenant->name.'</title>', false)
        ->assertSee('<meta name="description" content="First post body">', false)
        ->assertSee('og:image" content="http://acme.pagebuilder.test/storage/a.png"', false);

    Http::assertSent(fn ($request) => $request['page']['blocks'][0]['data']['value'] === 'Hello'
        && $request['page']['blocks'][2]['data']['items'] === [['label' => 'Ann', 'url' => null]]);
});

test('unpublished, unknown and unpublished-section records are 404', function () {
    $this->get('http://acme.pagebuilder.test/articles/hello')->assertNotFound();

    publishArticles();

    $this->get('http://acme.pagebuilder.test/articles/draft')->assertNotFound();
    $this->get('http://acme.pagebuilder.test/articles/nope')->assertNotFound();
    $this->get('http://acme.pagebuilder.test/authors/1')->assertNotFound();
    $this->get('http://pagebuilder/site/acme/articles/hello')->assertOk()->assertSee('noindex', false);
});

test('the record url field must identify records uniquely', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.collections.site', $this->articles), ['site_path' => 'articles', 'site_slug_field' => 'title'])
        ->assertJsonValidationErrors('site_slug_field');

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.site', $this->articles), ['site_path' => 'Bad Path', 'site_slug_field' => 'id'])
        ->assertJsonValidationErrors('site_path');

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.site', $this->pages), ['site_path' => 'pages', 'site_slug_field' => 'id'])
        ->assertStatus(422);
});

test('templates are sanitized like pages', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.collections.template', $this->articles), ['template' => [
            ['type' => 'button', 'props' => ['url' => 'javascript:alert(1)']],
        ]])
        ->assertOk()
        ->assertJsonPath('template.0.props.url', '');

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.template', $this->articles), ['template' => [['type' => 'evil']]])
        ->assertJsonValidationErrors('template');
});

test('records lists link to record pages and filter, sort and paginate', function () {
    publishArticles();
    $repository = new RecordRepository($this->pages);
    $home = $repository->firstWhere([['slug', 'home']]);

    $block = fn (array $props) => $repository->update($home['id'], ['content' => [
        ['type' => 'records', 'props' => ['collection' => 'articles', ...$props]],
    ]]);

    // Renders the page and returns the data of its first block, as sent to the SSR server.
    $items = fn () => function (string $url = 'http://acme.pagebuilder.test/') {
        $this->get($url)->assertOk();
        [$request] = Http::recorded()->last();

        return $request->data()['page']['blocks'][0]['data'];
    };

    // Unpublished records are hidden; cards link to their pages and carry the chosen image.
    $block(['image_field' => 'cover', 'sort' => 'title']);
    $data = $items()();
    expect(array_column($data['items'], 'title'))->toBe(['Guide', 'Hello'])
        ->and($data['items'][1]['url'])->toBe('/articles/hello')
        ->and($data['items'][1]['image'])->toBe('/storage/a.png');

    // Filters understand selects and relation titles.
    $block(['filter_field' => 'kind', 'filter_value' => 'guide']);
    expect(array_column($items()()['items'], 'title'))->toBe(['Guide']);

    $block(['filter_field' => 'author', 'filter_value' => 'Ann']);
    expect(array_column($items()()['items'], 'title'))->toBe(['Hello']);

    // Pagination reads ?page= on the site.
    $block(['limit' => 1, 'paginate' => true, 'sort' => 'oldest']);
    $page2 = $items()('http://acme.pagebuilder.test/?page=2');
    expect($page2['page'])->toBe(2)
        ->and($page2['last_page'])->toBe(2)
        ->and(array_column($page2['items'], 'title'))->toBe(['Guide']);
});

test('the editor previews record fields with a chosen record', function () {
    $hello = (new RecordRepository($this->articles))->firstWhere([['slug', 'hello']]);

    $this->actingAs($this->owner)
        ->postJson(route('api.blocks.resolve'), [
            'type' => 'record_field',
            'props' => ['field' => 'title', 'style' => 'h1'],
            'collection' => 'articles',
            'record_id' => $hello['id'],
        ])
        ->assertOk()
        ->assertJsonPath('data.value', 'Hello');

    $this->actingAs(memberOf(Tenant::factory()->create()))
        ->postJson(route('api.blocks.resolve'), [
            'type' => 'record_field', 'props' => ['field' => 'title'], 'collection' => 'articles', 'record_id' => $hello['id'],
        ])
        ->assertJsonPath('data.value', null);
});

test('image fields only accept safe urls', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.records.store', $this->articles), ['slug' => 'x', 'cover' => 'javascript:alert(1)'])
        ->assertJsonValidationErrors('cover');

    expect(Collection::withoutGlobalScope('tenant')->find($this->articles->id)->fields->firstWhere('name', 'cover')->type)
        ->toBe(FieldType::Image);
});

<?php

use App\Enums\FieldType;
use App\Models\BlockPattern;
use App\Models\Collection;
use App\Models\CustomTemplate;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\SiteDesign;
use App\Support\Site\SiteLanguages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('public');
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    // A source site with relations, a saved section, a translation, a file and design.
    $this->source = Tenant::factory()->create(['slug' => 'agency']);
    $this->owner = memberOf($this->source);
    $pages = app(PagesCollection::class)->ensure($this->source);
    $schema = app(SchemaManager::class);

    $authors = $schema->createCollection($this->source, ['name' => 'authors', 'label' => 'Авторы']);
    $schema->addField($authors, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String]);
    $posts = $schema->createCollection($this->source, ['name' => 'posts', 'label' => 'Статьи']);
    $schema->addField($posts, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String]);
    $schema->addField($posts, ['name' => 'author', 'label' => 'Автор', 'type' => FieldType::BelongsTo, 'related_collection_id' => $authors->id]);
    $schema->addField($posts, ['name' => 'coauthors', 'label' => 'Соавторы', 'type' => FieldType::BelongsToMany, 'related_collection_id' => $authors->id]);
    $leads = $schema->createCollection($this->source, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);

    $anna = (new RecordRepository($authors->fresh()))->create(['name' => 'Анна']);
    $max = (new RecordRepository($authors->fresh()))->create(['name' => 'Макс']);
    (new RecordRepository($posts->fresh()))->create(['title' => 'Первая', 'author' => $anna['id'], 'coauthors' => [$max['id']]]);
    (new RecordRepository($leads->fresh()))->create(['email' => 'private@example.com']);

    Storage::disk('public')->put("tenants/{$this->source->id}/2026/10/logo.png", 'png-bytes');
    (new Media)->forceFill([
        'tenant_id' => $this->source->id, 'disk' => 'public', 'path' => "tenants/{$this->source->id}/2026/10/logo.png",
        'original_name' => 'logo.png', 'mime_type' => 'image/png', 'size' => 9,
    ])->save();

    $section = new BlockPattern;
    $section->forceFill(['tenant_id' => $this->source->id, 'name' => 'Подвал-баннер', 'tree' => [['type' => 'heading', 'props' => ['text' => 'Секция']]]])->save();
    $this->sectionId = $section->id;

    $about = (new RecordRepository($pages))->create([
        'title' => 'О нас', 'slug' => 'about', 'is_published' => true,
        'content' => [
            ['type' => 'image', 'props' => ['src' => "/storage/tenants/{$this->source->id}/2026/10/logo.png"]],
            ['type' => 'pattern', 'props' => ['pattern' => $section->id]],
        ],
    ]);
    app(SiteLanguages::class)->save($pages, $about['id'], 'en', ['title' => 'About']);

    app(SiteDesign::class)->for($this->source)->forceFill([
        'theme' => ['primary' => '#ff0000'] + SiteDesign::DEFAULT_THEME,
        'menu' => [['label' => 'О нас', 'type' => 'page', 'page' => 'about', 'url' => '', 'children' => []]],
        'not_found' => [['type' => 'heading', 'props' => ['text' => 'Нет такой']]],
        'logo' => "/storage/tenants/{$this->source->id}/2026/10/logo.png",
    ])->save();
});

test('a site saved as a template recreates the whole site in a new company', function () {
    $key = $this->actingAs($this->owner)->postJson(route('api.custom-templates.store'), [
        'name' => 'Агентский блог',
        'record_collections' => ['authors', 'posts'],
    ])->assertCreated()->assertJsonPath('template.records', 3)->json('template.key');

    // The template appears in the starter list of its owner only.
    $this->actingAs($this->owner)->getJson(route('api.site-templates'))->assertJsonFragment(['key' => $key]);

    $this->actingAs($this->owner)->postJson(route('api.tenants.create'), ['name' => 'Клиент', 'template' => $key])->assertSuccessful();
    $client = Tenant::where('name', 'Клиент')->sole();

    // Schema and records with relations.
    $posts = Collection::withoutGlobalScope('tenant')->where('tenant_id', $client->id)->where('name', 'posts')->sole();
    $post = (new RecordRepository($posts))->firstWhere([['title', 'Первая']]);
    expect($post['author']['label'])->toBe('Анна')
        ->and(array_column($post['coauthors'], 'label'))->toBe(['Макс']);

    // Records of collections that were not chosen (form submissions) are not copied.
    $leads = Collection::withoutGlobalScope('tenant')->where('tenant_id', $client->id)->where('name', 'leads')->sole();
    expect(DB::table($leads->table_name)->count())->toBe(0);

    // Pages point at the new company's copy of the file and its own copy of the section.
    $newSection = BlockPattern::withoutGlobalScope('tenant')->where('tenant_id', $client->id)->sole();
    $clientPages = app(PagesCollection::class)->find($client);
    $about = (new RecordRepository($clientPages))->firstWhere([['slug', 'about']]);

    expect($about['content'][0]['props']['src'])->toBe("/storage/tenants/{$client->id}/2026/10/logo.png")
        ->and($about['content'][1]['props']['pattern'])->toBe($newSection->id)
        ->and($newSection->id)->not->toBe($this->sectionId);
    Storage::disk('public')->assertExists("tenants/{$client->id}/2026/10/logo.png");
    expect(Media::withoutGlobalScope('tenant')->where('tenant_id', $client->id)->count())->toBe(1);

    // Design, 404 page and translations.
    $settings = app(SiteDesign::class)->for($client);
    expect($settings->theme['primary'])->toBe('#ff0000')
        ->and($settings->menu[0]['page'])->toBe('about')
        ->and($settings->not_found[0]['props']['text'])->toBe('Нет такой')
        ->and($settings->logo)->toBe("/storage/tenants/{$client->id}/2026/10/logo.png")
        ->and(app(SiteLanguages::class)->forRecord($clientPages, $about['id'])['en']['title'])->toBe('About');

    $this->get("http://{$client->slug}.pagebuilder.test/about")->assertOk();

    // The template outlives the source company.
    $this->source->delete();
    $this->actingAs($this->owner)->postJson(route('api.tenants.create'), ['name' => 'Второй клиент', 'template' => $key])->assertSuccessful();
    Storage::disk('public')->assertExists('tenants/'.Tenant::where('name', 'Второй клиент')->sole()->id.'/2026/10/logo.png');
});

test('templates are private to their owner unless the platform publishes them', function () {
    $template = CustomTemplate::query()->findOrFail(
        $this->actingAs($this->owner)->postJson(route('api.custom-templates.store'), ['name' => 'Мой', 'record_collections' => []])->json('template.id'),
    );
    $stranger = memberOf(Tenant::factory()->create());

    $this->actingAs($stranger)->getJson(route('api.site-templates'))->assertJsonMissing(['key' => $template->key()]);
    $this->actingAs($stranger)->postJson(route('api.tenants.create'), ['name' => 'X', 'template' => $template->key()])->assertJsonValidationErrors('template');
    $this->actingAs($stranger)->deleteJson(route('api.custom-templates.destroy', $template))->assertNotFound();

    // Owners cannot publish; super admins can.
    $this->actingAs($this->owner)->putJson(route('api.custom-templates.update', $template), ['name' => 'Мой', 'is_public' => true])->assertOk();
    expect($template->fresh()->is_public)->toBeFalse();

    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin)->putJson(route('api.custom-templates.update', $template), ['name' => 'Мой', 'is_public' => true])->assertOk();

    $this->actingAs($stranger)->getJson(route('api.site-templates'))->assertJsonFragment(['key' => $template->key()]);

    $this->actingAs($this->owner)->deleteJson(route('api.custom-templates.destroy', $template))->assertOk();
    Storage::disk('public')->assertMissing($template->storageDirectory().'/2026/10/logo.png');
});

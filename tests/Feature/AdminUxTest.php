<?php

use App\Enums\FieldType;
use App\Enums\TenantRole;
use App\Models\BlockVersion;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\Forms;
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

test('the dashboard shows visits, submissions, drafts, scheduled pages and activity', function () {
    $schema = app(SchemaManager::class);
    $leads = $schema->createCollection($this->tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($leads, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String]);
    $token = app(Forms::class)->schema($this->tenant, $leads->fresh(), ['name'], withToken: true)['token'];
    $this->postJson(route('api.forms.submit'), ['token' => $token, 'values' => ['name' => 'Ivan']])->assertCreated();

    $repository = new RecordRepository($this->pages);
    $about = $repository->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);
    $repository->create(['title' => 'Анонс', 'slug' => 'soon', 'is_published' => true, 'publish_at' => now()->addDay()->toDateTimeString()]);
    $this->actingAs($this->owner)->putJson(route('api.block-versions.draft'), [
        'target' => "field:{$this->pages->name}:{$about['id']}:content",
        'tree' => [['type' => 'heading', 'props' => ['text' => 'Черновик']]],
    ])->assertOk();

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get('http://acme.pagebuilder.test/');

    $this->actingAs($this->owner)->getJson(route('api.dashboard'))
        ->assertOk()
        ->assertJsonPath('pages_collection', $this->pages->name)
        ->assertJsonPath('visits.views', 1)
        ->assertJsonPath('submissions.count', 1)
        ->assertJsonPath('submissions.latest.0.collection', 'leads')
        ->assertJsonPath('drafts.0.target', "field:{$this->pages->name}:{$about['id']}:content")
        ->assertJsonPath('scheduled.0.title', 'Анонс')
        ->assertJsonPath('activity.0.action', 'form.submitted');

    // Drafts of deleted pages are skipped instead of breaking the page.
    BlockVersion::query()->update(['target' => 'field:pages:999999:content']);
    $this->actingAs($this->owner)->getJson(route('api.dashboard'))->assertOk()->assertJsonPath('drafts', []);
});

test('viewers do not get the activity log on the dashboard', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Viewer))->getJson(route('api.dashboard'))
        ->assertOk()
        ->assertJsonPath('activity', null);
});

test('quick search finds records, collections and sections of the current company only', function () {
    $schema = app(SchemaManager::class);
    $products = $schema->createCollection($this->tenant, ['name' => 'products', 'label' => 'Товары']);
    $schema->addField($products, ['name' => 'title', 'label' => 'Название', 'type' => FieldType::String]);
    $products->forceFill(['display_field' => 'title'])->save();
    (new RecordRepository($products->fresh()))->create(['title' => 'Красный чайник']);

    $other = Tenant::factory()->create();
    $foreign = $schema->createCollection($other, ['name' => 'products', 'label' => 'Товары']);
    $schema->addField($foreign, ['name' => 'title', 'label' => 'Название', 'type' => FieldType::String]);
    (new RecordRepository($foreign->fresh()))->create(['title' => 'Красный утюг']);

    $this->actingAs($this->owner)->getJson(route('api.admin-search', ['q' => 'Красный']))
        ->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.title', 'Красный чайник')
        ->assertJsonPath('records.0.collection', 'products');

    $this->actingAs($this->owner)->getJson(route('api.admin-search', ['q' => 'товар']))
        ->assertJsonPath('collections.0.name', 'products');

    $this->actingAs($this->owner)->getJson(route('api.admin-search', ['q' => 'к']))
        ->assertJsonPath('records', []);
});

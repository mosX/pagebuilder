<?php

use App\Enums\FieldType;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\Scheduling;
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

/**
 * @return array<string, mixed>
 */
function scheduledPayload(string $path): array
{
    test()->get('http://acme.pagebuilder.test'.$path);
    [$request] = Http::recorded()->last();

    return json_decode((string) json_encode($request->data()), true);
}

test('a page scheduled for later appears at its time', function () {
    (new RecordRepository($this->pages))->create([
        'title' => 'Анонс', 'slug' => 'launch', 'is_published' => true,
        'publish_at' => now(Scheduling::DEFAULT_TIMEZONE)->addHour()->toDateTimeString(),
    ]);

    $this->get('http://acme.pagebuilder.test/launch')->assertNotFound();
    expect(array_column(scheduledPayload('/')['site']['pages'], 'slug'))->not->toContain('launch');
    $this->get('http://acme.pagebuilder.test/search?q=Анонс');
    expect(scheduledPayload('/search?q=Анонс')['page']['blocks'][0]['data']['results'])->toBe([]);

    $this->travel(61)->minutes();

    $this->get('http://acme.pagebuilder.test/launch')->assertOk();
    expect(array_column(scheduledPayload('/')['site']['pages'], 'slug'))->toContain('launch');
});

test('records of a section appear by their date field', function () {
    $schema = app(SchemaManager::class);
    $news = $schema->createCollection($this->tenant, ['name' => 'news', 'label' => 'Новости']);
    $schema->addField($news, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String]);
    $schema->addField($news, ['name' => 'date', 'label' => 'Дата', 'type' => FieldType::Date]);
    $schema->addField($news, ['name' => 'count', 'label' => 'Число', 'type' => FieldType::Integer]);

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.site', 'news'), ['site_path' => 'news', 'site_slug_field' => 'id', 'site_publish_at_field' => 'count'])
        ->assertJsonValidationErrors('site_publish_at_field');
    $this->actingAs($this->owner)
        ->putJson(route('api.collections.site', 'news'), ['site_path' => 'news', 'site_slug_field' => 'id', 'site_publish_at_field' => 'date'])
        ->assertOk()
        ->assertJsonPath('collection.site.publish_at_field', 'date');

    $records = new RecordRepository($news->fresh());
    $now = $records->create(['title' => 'Сегодня', 'date' => now()->toDateString()]);
    $later = $records->create(['title' => 'Завтра', 'date' => now()->addDay()->toDateString()]);
    $undated = $records->create(['title' => 'Без даты']);

    (new RecordRepository($this->pages))->update(
        (int) (new RecordRepository($this->pages))->firstWhere([['slug', 'home']])['id'],
        ['content' => [['type' => 'records', 'props' => ['collection' => 'news', 'limit' => 10]]]],
    );

    $this->get("http://acme.pagebuilder.test/news/{$now['id']}")->assertOk();
    $this->get("http://acme.pagebuilder.test/news/{$undated['id']}")->assertOk();
    $this->get("http://acme.pagebuilder.test/news/{$later['id']}")->assertNotFound();
    expect(array_column(scheduledPayload('/')['page']['blocks'][0]['data']['items'], 'title'))->toBe(['Без даты', 'Сегодня']);

    $this->travel(1)->days();
    $this->get("http://acme.pagebuilder.test/news/{$later['id']}")->assertOk();
});

test('cached sites are refreshed when scheduled content is released', function () {
    config(['tenancy.page_cache.enabled' => true]);
    (new RecordRepository($this->pages))->create([
        'title' => 'Анонс', 'slug' => 'launch', 'is_published' => true,
        'publish_at' => now(Scheduling::DEFAULT_TIMEZONE)->addMinutes(2)->toDateTimeString(),
    ]);
    $this->artisan('site:release-scheduled')->expectsOutput('Sites with newly released content: 0');

    $this->get('http://acme.pagebuilder.test/')->assertHeader('X-Site-Cache', 'miss');
    $this->get('http://acme.pagebuilder.test/')->assertHeader('X-Site-Cache', 'hit');

    $this->travel(3)->minutes();
    $this->artisan('site:release-scheduled')->expectsOutput('Sites with newly released content: 1');
    $this->get('http://acme.pagebuilder.test/')->assertHeader('X-Site-Cache', 'miss');
});

test('publication times are the company\'s local time', function () {
    $this->actingAs($this->owner)->putJson(route('api.company.update'), [
        'name' => $this->tenant->name,
        'slug' => $this->tenant->slug,
        'timezone' => 'Asia/Vladivostok',
    ])->assertOk()->assertJsonPath('company.timezone', 'Asia/Vladivostok');

    $vladivostok = now('Asia/Vladivostok');
    $repository = new RecordRepository($this->pages);
    // An hour ago in Vladivostok is still ahead of UTC: it must count as already published.
    $repository->create(['title' => 'Уже', 'slug' => 'past', 'is_published' => true, 'publish_at' => $vladivostok->copy()->subHour()->toDateTimeString()]);
    $repository->create(['title' => 'Позже', 'slug' => 'later', 'is_published' => true, 'publish_at' => $vladivostok->copy()->addHour()->toDateTimeString()]);

    $this->get('http://acme.pagebuilder.test/past')->assertOk();
    $this->get('http://acme.pagebuilder.test/later')->assertNotFound();

    $this->actingAs($this->owner)->putJson(route('api.company.update'), ['name' => 'X', 'slug' => 'acme', 'timezone' => 'Mars/Base'])
        ->assertJsonValidationErrors('timezone');
});

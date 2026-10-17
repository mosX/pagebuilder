<?php

use App\Enums\FieldType;
use App\Enums\TenantRole;
use App\Mail\FormSubmissionMail;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);

    $schema = app(SchemaManager::class);
    $this->authors = $schema->createCollection($this->tenant, ['name' => 'authors', 'label' => 'Авторы']);
    $schema->addField($this->authors, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String, 'is_required' => true]);
    $this->posts = $schema->createCollection($this->tenant, ['name' => 'posts', 'label' => 'Статьи']);
    $schema->addField($this->posts, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String]);
    $schema->addField($this->posts, ['name' => 'kind', 'label' => 'Тип', 'type' => FieldType::Select, 'options' => ['choices' => ['news', 'guide']]]);
    $schema->addField($this->posts, ['name' => 'author', 'label' => 'Автор', 'type' => FieldType::BelongsTo, 'related_collection_id' => $this->authors->id]);
});

function apiKey(bool $write = false, ?Tenant $tenant = null): string
{
    [, $token] = ApiKey::issue($tenant ?? test()->tenant, 'Test', $write, null);

    return $token;
}

test('validation messages are in Russian', function () {
    app()->setLocale('ru');

    $this->actingAs($this->owner)
        ->postJson(route('api.records.store', 'authors'), [])
        ->assertJsonPath('errors.name.0', 'Поле Имя обязательно.');
});

test('changes are written to the activity log of the company', function () {
    $this->actingAs($this->owner)->postJson(route('api.records.store', 'authors'), ['name' => 'Ann'])->assertCreated();

    $entry = Activity::withoutGlobalScope('tenant')->where('action', 'record.created')->sole();

    expect($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->subject)->toBe('Авторы #1');

    $this->actingAs($this->owner)
        ->getJson(route('api.activity.index'))
        ->assertOk()
        ->assertJsonPath('data.0.action', 'record.created')
        ->assertJsonPath('data.0.actor', $this->owner->name);
});

test('only owners and admins read the log, and only their own company', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Editor))->getJson(route('api.activity.index'))->assertForbidden();

    $this->actingAs($this->owner)->postJson(route('api.records.store', 'authors'), ['name' => 'Ann']);

    $this->actingAs(memberOf(Tenant::factory()->create()))
        ->getJson(route('api.activity.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('api keys are shown once, stored hashed and managed by admins only', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Editor))
        ->postJson(route('api.api-keys.store'), ['name' => 'Site'])
        ->assertForbidden();

    $token = $this->actingAs($this->owner)
        ->postJson(route('api.api-keys.store'), ['name' => 'Mobile app'])
        ->assertCreated()
        ->json('token');

    $key = ApiKey::withoutGlobalScope('tenant')->sole();

    expect($token)->toStartWith('pb_')
        ->and($key->token_hash)->toBe(hash('sha256', $token))
        ->and($key->token_hash)->not->toContain($token);

    $this->actingAs($this->owner)->getJson(route('api.api-keys.index'))->assertJsonMissingPath('0.token');
});

test('the headless api reads collections, records and pages with a key', function () {
    $this->getJson(route('api.v1.collections'))->assertUnauthorized();
    $this->withToken('pb_wrong')->getJson(route('api.v1.collections'))->assertUnauthorized();

    $ann = (new RecordRepository($this->authors->fresh()))->create(['name' => 'Ann']);
    $posts = new RecordRepository($this->posts->fresh());
    $posts->create(['title' => 'Hello', 'kind' => 'news', 'author' => $ann['id']]);
    $posts->create(['title' => 'Guide', 'kind' => 'guide']);
    app(PagesCollection::class)->ensure($this->tenant);

    $token = apiKey();

    $this->withToken($token)->getJson(route('api.v1.collections'))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['authors', 'pages', 'posts']);

    $this->withToken($token)->getJson(route('api.v1.records.index', ['collection' => 'posts', 'filter' => ['kind' => 'guide']]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.title', 'Guide');

    $this->withToken($token)->getJson(route('api.v1.records.show', ['collection' => 'posts', 'record' => 1]))
        ->assertJsonPath('data.author', ['id' => $ann['id'], 'label' => 'Ann']);

    $this->withToken($token)->getJson(route('api.v1.pages.show'))
        ->assertOk()
        ->assertJsonPath('data.slug', 'home');
});

test('writing needs a key with write access and is logged under the key', function () {
    $this->withToken(apiKey())->postJson(route('api.v1.records.store', 'authors'), ['name' => 'Bob'])->assertForbidden();

    $id = $this->withToken(apiKey(write: true))
        ->postJson(route('api.v1.records.store', 'authors'), ['name' => 'Bob'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(apiKey(write: true))->putJson(route('api.v1.records.update', ['collection' => 'authors', 'record' => $id]), ['name' => 'Robert'])->assertOk();
    $this->withToken(apiKey(write: true))->postJson(route('api.v1.records.store', 'authors'), [])->assertJsonValidationErrors('name');

    expect(Activity::withoutGlobalScope('tenant')->where('action', 'record.created')->sole()->actor)->toBe('API: Test');
});

test('a key never reaches another company and stops working when revoked', function () {
    $other = Tenant::factory()->create();
    $foreignToken = apiKey(tenant: $other);

    $this->withToken($foreignToken)->getJson(route('api.v1.records.index', 'posts'))->assertNotFound();

    $token = apiKey();
    $this->withToken($token)->getJson(route('api.v1.collections'))->assertOk();

    $key = ApiKey::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->sole();
    $this->actingAs($this->owner)->deleteJson(route('api.api-keys.destroy', $key->id))->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(route('api.v1.collections'))->assertUnauthorized();
});

test('the schema moves to another company with relations, site settings and templates', function () {
    $this->actingAs($this->owner)->putJson(route('api.collections.site', 'posts'), ['site_path' => 'blog', 'site_slug_field' => 'id'])->assertOk();
    $this->actingAs($this->owner)->putJson(route('api.collections.template', 'posts'), ['template' => [
        ['type' => 'record_field', 'props' => ['field' => 'title', 'style' => 'h1']],
    ]])->assertOk();

    $schema = $this->actingAs($this->owner)->getJson(route('api.schema.export'))->assertOk()->json();

    expect(array_column($schema['collections'], 'name'))->toBe(['authors', 'posts']);

    $target = Tenant::factory()->create();
    $admin = memberOf($target);

    $report = $this->actingAs($admin)
        ->postJson(route('api.schema.import'), ['schema' => $schema])
        ->assertOk()
        ->json('report');

    expect($report['errors'])->toBe([])
        ->and($report['created'])->toHaveCount(6);

    $posts = Collection::withoutGlobalScope('tenant')->where('tenant_id', $target->id)->where('name', 'posts')->sole();
    $author = $posts->fields->firstWhere('name', 'author');

    expect($author->relatedCollection->tenant_id)->toBe($target->id)
        ->and($posts->site_path)->toBe('blog')
        ->and($posts->site_template[0]['props']['field'] ?? null)->toBe('title');

    // A second import changes nothing.
    $this->actingAs($admin)
        ->postJson(route('api.schema.import'), ['schema' => $schema])
        ->assertJsonPath('report.created', [])
        ->assertJsonCount(6, 'report.skipped');
});

test('broken schema files are rejected or reported', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.schema.import'), ['schema' => ['hello' => 'world']])
        ->assertJsonValidationErrors('schema');

    $report = $this->actingAs($this->owner)->postJson(route('api.schema.import'), ['schema' => [
        'format' => 'pagebuilder-schema',
        'collections' => [
            ['name' => 'Bad Name', 'fields' => []],
            ['name' => 'tags', 'label' => 'Tags', 'fields' => [
                ['name' => 'owner', 'type' => 'belongs_to', 'related_collection' => 'missing'],
                ['name' => 'id', 'type' => 'string'],
            ]],
        ],
    ]])->assertOk()->json('report');

    expect($report['created'])->toBe(['Коллекция «Tags»'])
        ->and($report['errors'])->toHaveCount(3);
});

test('form submissions notify the configured e-mail', function () {
    Mail::fake();
    $pages = app(PagesCollection::class)->ensure($this->tenant);
    $repository = new RecordRepository($pages);
    $home = $repository->firstWhere([['slug', 'home']]);
    $repository->update($home['id'], ['content' => [['type' => 'form', 'props' => [
        'collection' => 'authors', 'fields' => ['name'], 'notify_email' => 'sales@acme.test',
    ]]]]);

    $this->get('http://acme.pagebuilder.test/')->assertOk();
    [$request] = Http::recorded()->last();
    $token = $request->data()['page']['blocks'][0]['data']['token'];

    $this->postJson(route('api.forms.submit'), ['token' => $token, 'values' => ['name' => 'Lead']])->assertCreated();

    Mail::assertSent(FormSubmissionMail::class, fn (FormSubmissionMail $mail) => $mail->hasTo('sales@acme.test')
        && $mail->values === [['label' => 'Имя', 'value' => 'Lead']]);

    expect(Activity::withoutGlobalScope('tenant')->where('action', 'form.submitted')->sole()->actor)->toBe('Посетитель сайта');
});

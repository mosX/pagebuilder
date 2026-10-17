<?php

use App\Enums\FieldType;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);

    $schema = app(SchemaManager::class);
    $this->leads = $schema->createCollection($this->tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($this->leads, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String, 'is_required' => true]);
    $schema->addField($this->leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);
    $schema->addField($this->leads, ['name' => 'status', 'label' => 'Статус', 'type' => FieldType::Select, 'options' => ['choices' => ['new', 'won']]]);

    $this->secret = $schema->createCollection($this->tenant, ['name' => 'secret', 'label' => 'Secret']);
    $schema->addField($this->secret, ['name' => 'name', 'label' => 'Name', 'type' => FieldType::String]);
});

/**
 * Publishes a home page with a form and returns the form data the site renders.
 *
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function publishForm(array $props): array
{
    $repository = new RecordRepository(test()->pages);
    $home = $repository->firstWhere([['slug', 'home']]);
    $repository->update($home['id'], ['content' => [['type' => 'form', 'props' => $props]]]);

    test()->get('http://acme.pagebuilder.test/')->assertOk();
    [$request] = Http::recorded()->last();

    return $request->data()['page']['blocks'][0]['data'];
}

test('a published form lists chosen and required fields and carries a token', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['email']]);

    expect(array_column($form['fields'], 'name'))->toBe(['email', 'name'])
        ->and($form['token'])->toBeString()
        ->and($form['error'])->toBeNull();
});

test('visitors create records through the form', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['name', 'email']]);

    $this->postJson(route('api.forms.submit'), [
        'token' => $form['token'],
        'values' => ['name' => 'Ann', 'email' => 'ann@example.com'],
    ])->assertCreated();

    expect(DB::table($this->leads->table_name)->where('name', 'Ann')->value('email'))->toBe('ann@example.com');
});

test('submissions are validated like records', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['name', 'email']]);

    $this->postJson(route('api.forms.submit'), [
        'token' => $form['token'],
        'values' => ['email' => 'not-an-email'],
    ])->assertJsonValidationErrors(['name', 'email']);
});

test('fields outside the form are ignored', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['name']]);

    $this->postJson(route('api.forms.submit'), [
        'token' => $form['token'],
        'values' => ['name' => 'Bob', 'status' => 'won'],
    ])->assertCreated();

    expect(DB::table($this->leads->table_name)->where('name', 'Bob')->value('status'))->toBeNull();
});

test('tokens cannot be forged for other collections', function () {
    $this->postJson(route('api.forms.submit'), ['token' => 'garbage', 'values' => ['name' => 'x']])
        ->assertJsonValidationErrors('form');

    // A token for a collection of another company is useless here and vice versa: it is bound to its tenant.
    $other = Tenant::factory()->create();
    $forged = Crypt::encryptString(json_encode(['t' => $other->id, 'c' => $this->secret->id, 'f' => ['name']]));

    $this->postJson(route('api.forms.submit'), ['token' => $forged, 'values' => ['name' => 'x']])
        ->assertJsonValidationErrors('form');

    expect(DB::table($this->secret->table_name)->count())->toBe(0);
});

test('the editor preview never receives a submit token', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Viewer))
        ->postJson(route('api.blocks.resolve'), ['type' => 'form', 'props' => ['collection' => 'secret', 'fields' => ['name']]])
        ->assertOk()
        ->assertJsonPath('data.token', null)
        ->assertJsonPath('data.fields.0.name', 'name');
});

test('bots filling the honeypot are rejected', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['name']]);

    $this->postJson(route('api.forms.submit'), [
        'token' => $form['token'], 'values' => ['name' => 'Bot'], 'website' => 'http://spam.example',
    ])->assertJsonValidationErrors('website');
});

test('forms of inactive companies stop accepting submissions', function () {
    $form = publishForm(['collection' => 'leads', 'fields' => ['name']]);
    $this->tenant->update(['is_active' => false]);

    $this->postJson(route('api.forms.submit'), ['token' => $form['token'], 'values' => ['name' => 'x']])
        ->assertJsonValidationErrors('form');
});

test('a required field a form cannot fill disables the form', function () {
    app(SchemaManager::class)->addField($this->leads, [
        'name' => 'owner', 'label' => 'Ответственный', 'type' => FieldType::BelongsTo,
        'related_collection_id' => $this->secret->id, 'is_required' => true,
    ]);

    $form = publishForm(['collection' => 'leads', 'fields' => ['name']]);

    expect($form['token'])->toBeNull()
        ->and($form['error'])->toContain('Ответственный');
});

test('records show the records that link to them', function () {
    $schema = app(SchemaManager::class);
    $authors = $schema->createCollection($this->tenant, ['name' => 'authors', 'label' => 'Authors']);
    $schema->addField($authors, ['name' => 'name', 'label' => 'Name', 'type' => FieldType::String]);
    $posts = $schema->createCollection($this->tenant, ['name' => 'posts', 'label' => 'Posts']);
    $schema->addField($posts, ['name' => 'title', 'label' => 'Title', 'type' => FieldType::String]);
    $schema->addField($posts, ['name' => 'author', 'label' => 'Author', 'type' => FieldType::BelongsTo, 'related_collection_id' => $authors->id]);
    $schema->addField($posts, ['name' => 'reviewers', 'label' => 'Reviewers', 'type' => FieldType::BelongsToMany, 'related_collection_id' => $authors->id]);

    $ann = (new RecordRepository($authors->fresh()))->create(['name' => 'Ann']);
    $bob = (new RecordRepository($authors->fresh()))->create(['name' => 'Bob']);
    $repository = new RecordRepository($posts->fresh());
    $repository->create(['title' => 'One', 'author' => $ann['id']]);
    $repository->create(['title' => 'Two', 'author' => $ann['id'], 'reviewers' => [$bob['id']]]);

    $this->actingAs($this->owner)
        ->getJson(route('api.records.backlinks', ['collection' => 'authors', 'record' => $ann['id']]))
        ->assertOk()
        ->assertJsonPath('*.field.name', ['author', 'reviewers'])
        ->assertJsonPath('0.total', 2)
        ->assertJsonPath('0.items.*.label', ['Two', 'One'])
        ->assertJsonPath('1.total', 0);

    $this->actingAs($this->owner)
        ->getJson(route('api.records.backlinks', ['collection' => 'authors', 'record' => $bob['id']]))
        ->assertJsonPath('1.field.name', 'reviewers')
        ->assertJsonPath('1.items.0.label', 'Two');

    $this->actingAs(memberOf(Tenant::factory()->create()))
        ->getJson(route('api.records.backlinks', ['collection' => 'authors', 'record' => $ann['id']]))
        ->assertNotFound();
});

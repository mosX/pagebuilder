<?php

use App\Enums\FieldType;
use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
});

function homeId(): int
{
    return (int) DB::table(test()->pages->table_name)->where('slug', 'home')->value('id');
}

test('the registry reads block definitions shared with the frontend', function () {
    $registry = app(BlockRegistry::class);

    expect(array_keys($registry->all()))->toContain('section', 'heading', 'text', 'records')
        ->and($registry->get('section')['children'] ?? false)->toBeTrue();
});

test('sanitizing keeps declared props, fills defaults and drops the rest', function () {
    $tree = app(BlockRegistry::class)->sanitize([
        ['id' => 'a1', 'type' => 'heading', 'props' => ['text' => 'Hi', 'level' => 'h9', 'onclick' => 'x'], 'children' => [['type' => 'text']]],
    ], 'content');

    $props = (array) $tree[0]['props'];

    expect($tree[0]['id'])->toBe('a1')
        ->and($props)->toBe(['text' => 'Hi', 'level' => 'h2', 'align' => 'left'])
        ->and($tree[0])->not->toHaveKey('children');
});

test('unsafe urls are removed and only safe ones are kept', function (string $url, string $expected) {
    $tree = app(BlockRegistry::class)->sanitize([['type' => 'button', 'props' => ['url' => $url]]], 'content');

    expect(((array) $tree[0]['props'])['url'])->toBe($expected);
})->with([
    ['javascript:alert(1)', ''],
    ['JaVaScRiPt:alert(1)', ''],
    ['data:text/html,x', ''],
    ['//evil.example', ''],
    ['https://example.com', 'https://example.com'],
    ['/site/acme/about', '/site/acme/about'],
    ['#contacts', '#contacts'],
    ['mailto:a@b.c', 'mailto:a@b.c'],
]);

test('unknown blocks and oversized trees are rejected', function () {
    $registry = app(BlockRegistry::class);

    expect(fn () => $registry->sanitize([['type' => 'script']], 'content'))->toThrow(ValidationException::class);

    $deep = ['type' => 'section', 'children' => []];
    for ($i = 0; $i < BlockRegistry::MAX_DEPTH + 1; $i++) {
        $deep = ['type' => 'section', 'children' => [$deep]];
    }
    expect(fn () => $registry->sanitize([$deep], 'content'))->toThrow(ValidationException::class);

    $many = array_fill(0, BlockRegistry::MAX_NODES + 1, ['type' => 'spacer']);
    expect(fn () => $registry->sanitize($many, 'content'))->toThrow(ValidationException::class);
});

test('every company gets a pages collection with a published home page', function () {
    expect($this->pages->kind)->toBe('pages')
        ->and($this->pages->fields->pluck('name')->all())->toBe(['title', 'slug', 'description', 'content', 'is_published', 'members_only'])
        ->and($this->pages->fields->every->is_system)->toBeTrue();

    $home = (new RecordRepository($this->pages))->find(homeId());

    expect($home['is_published'])->toBeTrue()
        ->and(collect($home['content'])->pluck('type')->all())->toBe(['hero', 'section']);
});

test('registration and onboarding provision the pages collection', function () {
    $this->postJson(route('api.register'), [
        'company' => 'New Co', 'name' => 'Jane', 'email' => 'jane@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertCreated();

    $tenant = Tenant::where('name', 'New Co')->sole();
    expect(app(PagesCollection::class)->find($tenant))->not->toBeNull();
});

test('the system pages collection and its fields cannot be removed', function () {
    $slug = $this->pages->fields->firstWhere('name', 'slug');

    $this->actingAs($this->owner)
        ->deleteJson(route('api.collections.destroy', $this->pages))
        ->assertJsonValidationErrors('collection');

    $this->actingAs($this->owner)
        ->deleteJson(route('api.collections.fields.destroy', [$this->pages, $slug]))
        ->assertJsonValidationErrors('field');

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.fields.update', [$this->pages, $slug]), ['label' => 'URL', 'is_unique' => false, 'is_required' => false])
        ->assertOk();

    expect($slug->fresh())->label->toBe('URL')->is_unique->toBeTrue()->is_required->toBeTrue();
});

test('saving a page stores the sanitized block tree', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$this->pages, homeId()]), [
            'content' => [
                ['type' => 'section', 'props' => ['padding' => 'small'], 'children' => [
                    ['type' => 'button', 'props' => ['label' => 'Go', 'url' => 'javascript:alert(1)']],
                ]],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('record.content.0.props.padding', 'small')
        ->assertJsonPath('record.content.0.children.0.props.label', 'Go')
        ->assertJsonPath('record.content.0.children.0.props.url', '');

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$this->pages, homeId()]), ['content' => [['type' => 'iframe']]])
        ->assertJsonValidationErrors('content');
});

test('the public site serves published pages only', function () {
    $repository = new RecordRepository($this->pages);
    $repository->create(['title' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
    $repository->create(['title' => 'About', 'slug' => 'about', 'is_published' => true]);

    $this->getJson(route('api.site.show', 'acme'))
        ->assertOk()
        ->assertJsonPath('name', $this->tenant->name)
        ->assertJsonPath('pages.*.slug', ['home', 'about']);

    $this->getJson(route('api.site.page', ['site' => 'acme']))->assertOk()->assertJsonPath('slug', 'home');
    $this->getJson(route('api.site.page', ['site' => 'acme', 'slug' => 'about']))->assertOk();
    $this->getJson(route('api.site.page', ['site' => 'acme', 'slug' => 'draft']))->assertNotFound();
    $this->getJson(route('api.site.page', ['site' => 'nope']))->assertNotFound();

    $this->tenant->update(['is_active' => false]);
    $this->getJson(route('api.site.show', 'acme'))->assertNotFound();
});

test('records blocks show the title and only the explicitly chosen field', function () {
    $schema = app(SchemaManager::class);
    $team = $schema->createCollection($this->tenant, ['name' => 'team', 'label' => 'Team']);
    $schema->addField($team, ['name' => 'name', 'label' => 'Name', 'type' => FieldType::String]);
    $schema->addField($team, ['name' => 'role', 'label' => 'Role', 'type' => FieldType::String]);
    $schema->addField($team, ['name' => 'salary', 'label' => 'Salary', 'type' => FieldType::Integer]);
    (new RecordRepository($team->fresh()))->create(['name' => 'Ann', 'role' => 'CEO', 'salary' => 100]);

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$this->pages, homeId()]), [
            'content' => [['type' => 'records', 'props' => ['collection' => 'team', 'subtitle_field' => 'role', 'limit' => 5]]],
        ])->assertOk();

    $this->getJson(route('api.site.page', ['site' => 'acme']))
        ->assertOk()
        ->assertJsonPath('blocks.0.data.items', [['id' => 1, 'title' => 'Ann', 'subtitle' => 'CEO', 'image' => null, 'url' => null]])
        ->assertDontSee('100');
});

test('blocks cannot reference another company collection', function () {
    $other = Tenant::factory()->create();
    $schema = app(SchemaManager::class);
    $secret = $schema->createCollection($other, ['name' => 'secret', 'label' => 'Secret']);
    $schema->addField($secret, ['name' => 'name', 'label' => 'Name', 'type' => FieldType::String]);
    (new RecordRepository($secret->fresh()))->create(['name' => 'classified']);

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$this->pages, homeId()]), [
            'content' => [['type' => 'records', 'props' => ['collection' => 'secret']]],
        ])->assertJsonPath('record.content.0.props.collection', '');

    $this->actingAs($this->owner)
        ->postJson(route('api.blocks.resolve'), ['type' => 'records', 'props' => ['collection' => 'secret']])
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertDontSee('classified');
});

test('the editor preview resolves data blocks of the current company', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Viewer))
        ->postJson(route('api.blocks.resolve'), ['type' => 'records', 'props' => ['collection' => $this->pages->name]])
        ->assertOk()
        ->assertJsonPath('data.items.0.title', 'Главная');

    $this->actingAs($this->owner)
        ->postJson(route('api.blocks.resolve'), ['type' => 'unknown'])
        ->assertJsonValidationErrors('type');
});

test('deleting a company drops its pages table', function () {
    $table = $this->pages->table_name;
    $this->tenant->delete();

    expect(Schema::hasTable($table))->toBeFalse()
        ->and(Collection::withoutGlobalScope('tenant')->count())->toBe(0);
});

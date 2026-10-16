<?php

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Collections\RecordRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->owner = memberOf($this->tenant);
});

function createCollection(string $name, string $label = 'Коллекция'): Collection
{
    test()->actingAs(test()->owner)
        ->postJson(route('api.collections.store'), ['name' => $name, 'label' => $label])
        ->assertCreated();

    return Collection::withoutGlobalScope('tenant')->where('tenant_id', test()->tenant->id)->where('name', $name)->sole();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function addField(Collection $collection, array $attributes): void
{
    test()->actingAs(test()->owner)
        ->postJson(route('api.collections.fields.store', $collection), ['label' => $attributes['name'], ...$attributes])
        ->assertCreated();
}

test('creating a collection creates its table', function () {
    $collection = createCollection('products', 'Товары');

    expect($collection->table_name)->toBe("c{$this->tenant->id}_products")
        ->and(Schema::hasTable($collection->table_name))->toBeTrue()
        ->and(Schema::hasColumns($collection->table_name, ['id', 'created_at', 'updated_at']))->toBeTrue();
});

test('collection names are validated', function (string $name) {
    $this->actingAs($this->owner)
        ->postJson(route('api.collections.store'), ['name' => $name, 'label' => 'X'])
        ->assertJsonValidationErrors('name');
})->with(['Products', '1items', 'with-dash', 'way_too_long_collection_name']);

test('scalar fields become real columns', function () {
    $collection = createCollection('products');

    addField($collection, ['name' => 'title', 'type' => 'string', 'is_required' => true]);
    addField($collection, ['name' => 'body', 'type' => 'text']);
    addField($collection, ['name' => 'price', 'type' => 'decimal', 'options' => ['scale' => 2]]);
    addField($collection, ['name' => 'stock', 'type' => 'integer']);
    addField($collection, ['name' => 'active', 'type' => 'boolean']);
    addField($collection, ['name' => 'released', 'type' => 'date']);
    addField($collection, ['name' => 'size', 'type' => 'select', 'options' => ['choices' => ['S', 'M', 'L']]]);

    expect(Schema::hasColumns($collection->table_name, ['title', 'body', 'price', 'stock', 'active', 'released', 'size']))->toBeTrue();
});

test('field names are validated', function () {
    $collection = createCollection('products');
    addField($collection, ['name' => 'title', 'type' => 'string']);

    foreach (['id', 'author_id', 'Title', 'title'] as $name) {
        $this->actingAs($this->owner)
            ->postJson(route('api.collections.fields.store', $collection), ['name' => $name, 'label' => 'X', 'type' => 'string'])
            ->assertJsonValidationErrors('name');
    }

    $this->actingAs($this->owner)
        ->postJson(route('api.collections.fields.store', $collection), ['name' => 'size', 'label' => 'X', 'type' => 'select'])
        ->assertJsonValidationErrors('options.choices');
});

test('records are created, validated, updated and deleted', function () {
    $collection = createCollection('products');
    addField($collection, ['name' => 'title', 'type' => 'string', 'is_required' => true]);
    addField($collection, ['name' => 'price', 'type' => 'decimal']);
    addField($collection, ['name' => 'active', 'type' => 'boolean']);
    addField($collection, ['name' => 'size', 'type' => 'select', 'options' => ['choices' => ['S', 'M']]]);

    $this->actingAs($this->owner)
        ->postJson(route('api.records.store', $collection), ['price' => 'abc', 'size' => 'XL'])
        ->assertJsonValidationErrors(['title', 'price', 'size']);

    $id = $this->actingAs($this->owner)
        ->postJson(route('api.records.store', $collection), ['title' => 'Mug', 'price' => 9.5, 'active' => true, 'size' => 'M'])
        ->assertCreated()
        ->assertJsonPath('record.title', 'Mug')
        ->assertJsonPath('record.price', '9.50')
        ->assertJsonPath('record.active', true)
        ->json('record.id');

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$collection, $id]), ['title' => 'Big mug'])
        ->assertOk()
        ->assertJsonPath('record.title', 'Big mug')
        ->assertJsonPath('record.size', 'M');

    $this->actingAs($this->owner)
        ->getJson(route('api.records.index', [$collection, 'search' => 'big']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $id);

    $this->actingAs($this->owner)->deleteJson(route('api.records.destroy', [$collection, $id]))->assertOk();

    expect(DB::table($collection->table_name)->count())->toBe(0);
});

test('unique fields reject duplicates and can be toggled', function () {
    $collection = createCollection('products');
    addField($collection, ['name' => 'sku', 'type' => 'string', 'is_unique' => true]);
    $field = $collection->fields()->sole();

    $this->actingAs($this->owner)->postJson(route('api.records.store', $collection), ['sku' => 'A1'])->assertCreated();
    $this->actingAs($this->owner)->postJson(route('api.records.store', $collection), ['sku' => 'A1'])->assertJsonValidationErrors('sku');

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.fields.update', [$collection, $field]), ['label' => 'SKU', 'is_unique' => false])
        ->assertOk();
    $this->actingAs($this->owner)->postJson(route('api.records.store', $collection), ['sku' => 'A1'])->assertCreated();

    $this->actingAs($this->owner)
        ->putJson(route('api.collections.fields.update', [$collection, $field]), ['label' => 'SKU', 'is_unique' => true])
        ->assertJsonValidationErrors('is_unique');
});

test('belongs-to and many-to-many relations store and resolve records', function () {
    $authors = createCollection('authors');
    addField($authors, ['name' => 'name', 'type' => 'string']);
    $tags = createCollection('tags');
    addField($tags, ['name' => 'title', 'type' => 'string']);
    $posts = createCollection('posts');
    addField($posts, ['name' => 'title', 'type' => 'string']);
    addField($posts, ['name' => 'author', 'type' => 'belongs_to', 'related_collection_id' => $authors->id]);
    addField($posts, ['name' => 'tags', 'type' => 'belongs_to_many', 'related_collection_id' => $tags->id]);

    expect(Schema::hasColumn($posts->table_name, 'author_id'))->toBeTrue()
        ->and(Schema::hasTable("{$posts->table_name}__tags"))->toBeTrue();

    $authorId = DB::table($authors->table_name)->insertGetId(['name' => 'Ann']);
    $tagA = DB::table($tags->table_name)->insertGetId(['title' => 'php']);
    $tagB = DB::table($tags->table_name)->insertGetId(['title' => 'vue']);

    $this->actingAs($this->owner)
        ->postJson(route('api.records.store', $posts), ['title' => 'Hi', 'author' => 999, 'tags' => [999]])
        ->assertJsonValidationErrors(['author', 'tags.0']);

    $id = $this->actingAs($this->owner)
        ->postJson(route('api.records.store', $posts), ['title' => 'Hi', 'author' => $authorId, 'tags' => [$tagB, $tagA]])
        ->assertCreated()
        ->assertJsonPath('record.author', ['id' => $authorId, 'label' => 'Ann'])
        ->assertJsonPath('record.tags', [['id' => $tagB, 'label' => 'vue'], ['id' => $tagA, 'label' => 'php']])
        ->json('record.id');

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', [$posts, $id]), ['tags' => [$tagA]])
        ->assertJsonPath('record.tags', [['id' => $tagA, 'label' => 'php']]);

    $this->actingAs($this->owner)
        ->getJson(route('api.records.options', [$authors, 'search' => 'an']))
        ->assertExactJson([['id' => $authorId, 'label' => 'Ann']]);
});

test('a referenced collection cannot be dropped until the relation is removed', function () {
    $authors = createCollection('authors');
    $posts = createCollection('posts');
    addField($posts, ['name' => 'author', 'type' => 'belongs_to', 'related_collection_id' => $authors->id]);
    $relation = $posts->fields()->sole();

    $this->actingAs($this->owner)
        ->deleteJson(route('api.collections.destroy', $authors))
        ->assertJsonValidationErrors('collection');

    $this->actingAs($this->owner)
        ->deleteJson(route('api.collections.fields.destroy', [$posts, $relation]))
        ->assertOk();

    expect(Schema::hasColumn($posts->table_name, 'author_id'))->toBeFalse();

    $this->actingAs($this->owner)->deleteJson(route('api.collections.destroy', $authors))->assertOk();

    expect(Schema::hasTable($authors->table_name))->toBeFalse()
        ->and(Collection::withoutGlobalScope('tenant')->find($authors->id))->toBeNull();
});

test('dropping a collection removes its pivot tables', function () {
    $tags = createCollection('tags');
    $posts = createCollection('posts');
    addField($posts, ['name' => 'tags', 'type' => 'belongs_to_many', 'related_collection_id' => $tags->id]);

    $this->actingAs($this->owner)->deleteJson(route('api.collections.destroy', $posts))->assertOk();

    expect(Schema::hasTable("{$posts->table_name}__tags"))->toBeFalse()
        ->and(Schema::hasTable($posts->table_name))->toBeFalse();
});

test('collections are isolated between companies', function () {
    $collection = createCollection('products');
    $stranger = memberOf(Tenant::factory()->create());

    $this->actingAs($stranger)->getJson(route('api.collections.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($stranger)->getJson(route('api.collections.show', $collection))->assertNotFound();
    $this->actingAs($stranger)->getJson(route('api.records.index', $collection))->assertNotFound();
    $this->actingAs($stranger)->postJson(route('api.records.store', $collection), [])->assertNotFound();
});

test('relations cannot point to another company collection', function () {
    $posts = createCollection('posts');
    $foreign = (new Collection)->forceFill([
        'name' => 'secret',
        'label' => 'Secret',
        'tenant_id' => Tenant::factory()->create()->id,
        'table_name' => 'c0_secret',
    ]);
    $foreign->save();

    $this->actingAs($this->owner)
        ->postJson(route('api.collections.fields.store', $posts), [
            'name' => 'secret', 'label' => 'Secret', 'type' => 'belongs_to', 'related_collection_id' => $foreign->id,
        ])->assertJsonValidationErrors('related_collection_id');
});

test('editors manage records but not the schema; viewers only read', function () {
    $collection = createCollection('products');
    addField($collection, ['name' => 'title', 'type' => 'string']);
    $editor = memberOf($this->tenant, TenantRole::Editor);
    $viewer = memberOf($this->tenant, TenantRole::Viewer);

    $this->actingAs($editor)->postJson(route('api.collections.store'), ['name' => 'x', 'label' => 'X'])->assertForbidden();
    $this->actingAs($editor)->postJson(route('api.records.store', $collection), ['title' => 'A'])->assertCreated();

    $this->actingAs($viewer)->getJson(route('api.records.index', $collection))->assertOk();
    $this->actingAs($viewer)->postJson(route('api.records.store', $collection), ['title' => 'B'])->assertForbidden();
});

test('deleting a company drops its collection tables', function () {
    $tags = createCollection('tags');
    $posts = createCollection('posts');
    addField($posts, ['name' => 'tags', 'type' => 'belongs_to_many', 'related_collection_id' => $tags->id]);
    addField($tags, ['name' => 'post', 'type' => 'belongs_to', 'related_collection_id' => $posts->id]);

    $this->tenant->delete();

    expect(Schema::hasTable($posts->table_name))->toBeFalse()
        ->and(Schema::hasTable($tags->table_name))->toBeFalse()
        ->and(Schema::hasTable("{$posts->table_name}__tags"))->toBeFalse()
        ->and(Collection::withoutGlobalScope('tenant')->count())->toBe(0);
});

test('records with relations can be written outside a request, without tenant context', function () {
    $tags = createCollection('tags');
    addField($tags, ['name' => 'title', 'type' => 'string']);
    $posts = createCollection('posts');
    addField($posts, ['name' => 'tags', 'type' => 'belongs_to_many', 'related_collection_id' => $tags->id]);

    app(TenantContext::class)->forget();

    $tag = (new RecordRepository($tags->fresh()))->create(['title' => 'php']);
    $post = (new RecordRepository($posts->fresh()))->create(['tags' => [$tag['id']]]);

    expect($post['tags'])->toBe([['id' => $tag['id'], 'label' => 'php']]);
});

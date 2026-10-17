<?php

use App\Enums\TenantRole;
use App\Models\BlockPattern;
use App\Models\BlockVersion;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\RichText;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    // API routes stay on the platform host even after requests to a company subdomain.
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);
    $this->pages = app(PagesCollection::class)->ensure($this->tenant);
    $this->home = (new RecordRepository($this->pages))->firstWhere([['slug', 'home']]);
    $this->target = "field:{$this->pages->name}:{$this->home['id']}:content";
});

function heading(string $text): array
{
    return ['type' => 'heading', 'props' => ['text' => $text]];
}

function sitePayload(): array
{
    test()->get('http://acme.pagebuilder.test/')->assertOk();
    [$request] = Http::recorded()->last();

    return $request->data();
}

test('drafts are not visible on the site until published', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.block-versions.draft'), ['target' => $this->target, 'tree' => [heading('Draft title')]])
        ->assertOk();

    expect(sitePayload()['page']['blocks'][0]['type'])->toBe('hero');

    $this->actingAs($this->owner)
        ->getJson(route('api.block-versions.show', ['target' => $this->target]))
        ->assertOk()
        ->assertJsonPath('draft.tree.0.props.text', 'Draft title')
        ->assertJsonPath('published.0.type', 'hero');

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => [heading('Live title')]])
        ->assertOk()
        ->assertJsonPath('tree.0.props.text', 'Live title');

    expect(sitePayload()['page']['blocks'][0]['props']['text'])->toBe('Live title')
        ->and(BlockVersion::withoutGlobalScope('tenant')->where('kind', 'draft')->count())->toBe(0);
});

test('every publication keeps a revision that can be restored', function () {
    foreach (['One', 'Two', 'Three'] as $title) {
        $this->actingAs($this->owner)
            ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => [heading($title)]])
            ->assertOk();
    }

    $revisions = $this->actingAs($this->owner)
        ->getJson(route('api.block-versions.show', ['target' => $this->target]))
        ->assertJsonCount(3, 'revisions')
        ->json('revisions');

    $this->actingAs($this->owner)
        ->getJson(route('api.block-versions.revision', $revisions[2]['id']))
        ->assertOk()
        ->assertJsonPath('tree.0.props.text', 'One');
});

test('targets are confined to the company and to the right permissions', function () {
    $stranger = memberOf(Tenant::factory()->create());
    $viewer = memberOf($this->tenant, TenantRole::Viewer);
    $editor = memberOf($this->tenant, TenantRole::Editor);

    $this->actingAs($stranger)
        ->putJson(route('api.block-versions.draft'), ['target' => $this->target, 'tree' => []])
        ->assertNotFound();

    $this->actingAs($viewer)
        ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => []])
        ->assertForbidden();

    // Templates change the site structure: editors may not publish them.
    $this->actingAs($editor)
        ->postJson(route('api.block-versions.publish'), ['target' => "template:{$this->pages->name}", 'tree' => []])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->putJson(route('api.block-versions.draft'), ['target' => "field:{$this->pages->name}:{$this->home['id']}:title", 'tree' => []])
        ->assertJsonValidationErrors('target');

    $this->actingAs($this->owner)
        ->putJson(route('api.block-versions.draft'), ['target' => 'nonsense', 'tree' => []])
        ->assertJsonValidationErrors('target');
});

test('saved sections render on every page that uses them and update everywhere', function () {
    $id = $this->actingAs($this->owner)
        ->postJson(route('api.patterns.store'), ['name' => 'CTA', 'tree' => [heading('Call us')]])
        ->assertCreated()
        ->json('pattern.id');

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => [
            ['type' => 'pattern', 'props' => ['pattern' => $id]],
        ]])->assertOk();

    expect(sitePayload()['page']['blocks'][0]['data']['blocks'][0]['props']['text'])->toBe('Call us');

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => "pattern:{$id}", 'tree' => [heading('Write us')]])
        ->assertOk();

    expect(sitePayload()['page']['blocks'][0]['data']['blocks'][0]['props']['text'])->toBe('Write us');
});

test('a section that includes itself does not loop', function () {
    $pattern = new BlockPattern(['name' => 'Loop', 'tree' => []]);
    $pattern->forceFill(['tenant_id' => $this->tenant->id])->save();
    $pattern->update(['tree' => [['type' => 'pattern', 'props' => ['pattern' => $pattern->id]]]]);

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => [
            ['type' => 'pattern', 'props' => ['pattern' => $pattern->id]],
        ]])->assertOk();

    $this->get('http://acme.pagebuilder.test/')->assertOk();
});

test('sections of another company never render', function () {
    $foreign = new BlockPattern(['name' => 'Secret', 'tree' => [heading('classified')]]);
    $foreign->forceFill(['tenant_id' => Tenant::factory()->create()->id])->save();

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => $this->target, 'tree' => [
            ['type' => 'pattern', 'props' => ['pattern' => $foreign->id]],
        ]])->assertOk();

    expect(sitePayload()['page']['blocks'][0])->not->toHaveKey('data');

    $this->actingAs($this->owner)
        ->getJson(route('api.block-versions.show', ['target' => "pattern:{$foreign->id}"]))
        ->assertNotFound();
});

test('rich text keeps allowed formatting only', function () {
    $doc = app(RichText::class)->sanitize([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1, 'onclick' => 'x'], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'safe', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank']]]],
                ['type' => 'text', 'text' => 'evil', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'bold'], ['type' => 'script']]],
            ]],
            ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example']],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => '<script>']]],
        ],
    ]);

    expect($doc['content'])->toHaveCount(2)
        ->and($doc['content'][0]['attrs'])->toBe(['level' => 2])
        ->and($doc['content'][1]['content'][0]['marks'])->toBe([['type' => 'link', 'attrs' => ['href' => 'https://example.com']]])
        ->and($doc['content'][1]['content'][1]['marks'])->toBe([['type' => 'bold']]);
});

test('plain text of older blocks becomes paragraphs', function () {
    $tree = app(BlockRegistry::class)->sanitize([['type' => 'text', 'props' => ['text' => "First\n\nSecond"]]], 'content');
    $text = ((array) $tree[0]['props'])['text'];

    expect($text['type'])->toBe('doc')
        ->and(array_column(array_column($text['content'], 'content'), 0))->toBe([
            ['type' => 'text', 'text' => 'First'],
            ['type' => 'text', 'text' => 'Second'],
        ]);
});

test('per-device visibility survives sanitizing and drops unknown devices', function () {
    $tree = app(BlockRegistry::class)->sanitize([
        ['type' => 'spacer', 'hide' => ['mobile', 'watch', 'mobile', 'desktop']],
        ['type' => 'spacer', 'hide' => 'mobile'],
    ], 'content');

    expect($tree[0]['hide'])->toBe(['mobile', 'desktop'])
        ->and($tree[1])->not->toHaveKey('hide');
});

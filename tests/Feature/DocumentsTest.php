<?php

use App\Models\Media;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('public');
    $this->tenant = Tenant::factory()->create();
    $this->owner = memberOf($this->tenant);
});

/**
 * Uploads a real temporary file: its type is sniffed from the content, as for real uploads.
 */
function uploadFile(string $name, string $content): TestResponse
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $content);

    return test()->actingAs(test()->owner)->post(route('api.media.store'), [
        'file' => new UploadedFile($path, $name, null, null, true),
    ], ['Accept' => 'application/json']);
}

test('documents are uploaded to the media library', function () {
    uploadFile('Прайс.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF")
        ->assertCreated()
        ->assertJsonPath('media.kind', 'document')
        ->assertJsonPath('media.extension', 'pdf')
        ->assertJsonPath('media.width', null);

    uploadFile('prices.csv', "name;price\nТовар;100\n")->assertCreated();

    $media = Media::query()->get();
    expect($media)->toHaveCount(2);
    Storage::disk('public')->assertExists($media->first()->path);
});

test('pages, scripts and svg cannot be uploaded under any name', function () {
    uploadFile('notes.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>')
        ->assertJsonValidationErrors('file');
    uploadFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
        ->assertJsonValidationErrors('file');
    uploadFile('run.php', '<?php echo 1;')->assertJsonValidationErrors('file');

    expect(Media::query()->count())->toBe(0);
});

test('pickers list images or documents separately', function () {
    uploadFile('doc.pdf', "%PDF-1.4\n%%EOF")->assertCreated();
    // A 1×1 PNG, built without GD.
    uploadFile('photo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))
        ->assertCreated();

    $this->actingAs($this->owner)->getJson(route('api.media.index', ['kind' => 'image']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'image');
    $this->actingAs($this->owner)->getJson(route('api.media.index', ['kind' => 'document']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.extension', 'pdf');
    $this->actingAs($this->owner)->getJson(route('api.media.index'))->assertJsonCount(2, 'data');
});

test('file links in blocks are limited to safe urls', function () {
    $tree = app(BlockRegistry::class)->sanitize([[
        'type' => 'files',
        'props' => ['items' => [
            ['title' => 'Ok', 'file' => '/storage/tenants/1/a.pdf'],
            ['title' => 'Bad', 'file' => 'javascript:alert(1)'],
        ]],
    ]], 'content');

    expect(array_column($tree[0]['props']->items, 'file'))->toBe(['/storage/tenants/1/a.pdf', '']);
});

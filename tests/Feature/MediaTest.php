<?php

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->tenant = Tenant::factory()->create();
    $this->owner = memberOf($this->tenant);
});

/**
 * A real 1×1 PNG (GD is not required to build it).
 */
function png(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
    );
}

test('images are uploaded into the company folder', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('api.media.store'), ['file' => png()])
        ->assertCreated()
        ->assertJsonPath('media.name', 'photo.png')
        ->assertJsonPath('media.width', 1)
        ->assertJsonPath('media.mime_type', 'image/png');

    $media = Media::withoutGlobalScope('tenant')->sole();

    expect($media->path)->toStartWith("tenants/{$this->tenant->id}/")
        ->and($response->json('media.url'))->toStartWith('/storage/tenants/');
    Storage::disk('public')->assertExists($media->path);
});

/**
 * A real upload whose MIME type is sniffed from the content (fake files take it from the name).
 */
function upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

test('only raster images are accepted', function (string $name, string $content) {
    $this->actingAs($this->owner)
        ->postJson(route('api.media.store'), ['file' => upload($name, $content)])
        ->assertJsonValidationErrors('file');
})->with([
    'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html renamed to png' => ['x.png', '<html><body><script>alert(1)</script></body></html>'],
    'php renamed to jpg' => ['x.jpg', '<?php echo 1;'],
]);

test('media is listed, updated and deleted within the company only', function () {
    $this->actingAs($this->owner)->postJson(route('api.media.store'), ['file' => png('a.png')])->assertCreated();
    $media = Media::withoutGlobalScope('tenant')->sole();
    $stranger = memberOf(Tenant::factory()->create());

    $this->actingAs($stranger)->getJson(route('api.media.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($stranger)->deleteJson(route('api.media.destroy', $media->id))->assertNotFound();

    $this->actingAs($this->owner)->getJson(route('api.media.index', ['search' => 'a.p']))->assertJsonCount(1, 'data');
    $this->actingAs($this->owner)
        ->putJson(route('api.media.update', $media->id), ['alt' => 'Logo'])
        ->assertOk()
        ->assertJsonPath('media.alt', 'Logo');

    $this->actingAs($this->owner)->deleteJson(route('api.media.destroy', $media->id))->assertOk();
    Storage::disk('public')->assertMissing($media->path);
});

test('viewers can browse but not upload', function () {
    $viewer = memberOf($this->tenant, TenantRole::Viewer);

    $this->actingAs($viewer)->getJson(route('api.media.index'))->assertOk();
    $this->actingAs($viewer)->postJson(route('api.media.store'), ['file' => png()])->assertForbidden();
});

test('deleting a company removes its files', function () {
    $this->actingAs($this->owner)->postJson(route('api.media.store'), ['file' => png()])->assertCreated();

    $this->tenant->delete();

    Storage::disk('public')->assertDirectoryEmpty('tenants');
});

<?php

namespace App\Support\Media;

use App\Http\Controllers\ImageController;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores company files under tenants/{tenant_id}/ on the media disk.
 */
class MediaLibrary
{
    /**
     * Raster images only: SVG can carry scripts and would run on our origin.
     */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public const MAX_KILOBYTES = 10240;

    public function disk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'public';
    }

    public function store(Tenant $tenant, UploadedFile $file, ?User $user = null): Media
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $directory = sprintf('tenants/%d/%s', $tenant->id, now()->format('Y/m'));
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, $this->disk());

        if ($path === false) {
            throw new \RuntimeException('The file could not be stored.');
        }

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = new Media;
        $media->forceFill([
            'tenant_id' => $tenant->id,
            'uploaded_by' => $user?->id,
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ])->save();

        return $media;
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);

        foreach (ImageController::WIDTHS as $width) {
            File::delete(public_path("img/{$width}/{$media->path}"));
        }

        $media->delete();
    }

    /**
     * Removes every file of a tenant; used when the tenant is deleted.
     */
    public function deleteTenant(Tenant $tenant): void
    {
        Storage::disk($this->disk())->deleteDirectory("tenants/{$tenant->id}");

        foreach (ImageController::WIDTHS as $width) {
            File::deleteDirectory(public_path("img/{$width}/tenants/{$tenant->id}"));
        }
    }
}

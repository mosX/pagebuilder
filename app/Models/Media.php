<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file in a company's media library.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['alt'])]
class Media extends Model
{
    use BelongsToTenant;

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

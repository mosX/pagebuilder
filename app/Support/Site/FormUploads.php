<?php

namespace App\Support\Site;

use App\Support\Media\MediaLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files visitors attach to site forms (résumés, scans). They are private: kept on the
 * local disk, never under a public URL, and downloaded from the admin only.
 * A record stores them as "private://form-uploads/{tenant}/{Y}/{m}/{uuid}/{name}".
 */
class FormUploads
{
    public const PREFIX = 'private://';

    public const MAX_KILOBYTES = 10240;

    public const DISK = 'local';

    public function store(int $tenantId, UploadedFile $file): string
    {
        $name = $this->safeName($file->getClientOriginalName(), (string) ($file->guessExtension() ?: 'bin'));
        $directory = sprintf('form-uploads/%d/%s/%s', $tenantId, now()->format('Y/m'), Str::uuid()->toString());

        Storage::disk(self::DISK)->putFileAs($directory, $file, $name);

        return self::PREFIX."{$directory}/{$name}";
    }

    public function isPrivate(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Whether a stored value is a file of this company (and not a path crafted to reach another one).
     */
    public function belongsTo(string $value, int $tenantId): bool
    {
        return str_starts_with($value, self::PREFIX."form-uploads/{$tenantId}/")
            && ! str_contains($value, '..')
            && ! str_contains($value, '\\');
    }

    public function path(string $value): string
    {
        return substr($value, strlen(self::PREFIX));
    }

    /**
     * The file name the visitor uploaded, for display and downloads.
     */
    public function name(string $value): string
    {
        return basename($this->path($value));
    }

    public function delete(string $value): void
    {
        Storage::disk(self::DISK)->deleteDirectory(dirname($this->path($value)));
    }

    /**
     * File extensions visitors may attach: the media library's images and documents.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return [...MediaLibrary::IMAGE_EXTENSIONS, ...MediaLibrary::DOCUMENT_EXTENSIONS];
    }

    /**
     * Keeps a readable name ("Резюме Иванов.pdf") without path tricks or odd characters.
     */
    private function safeName(string $name, string $extension): string
    {
        $base = (string) preg_replace('/[^\pL\pN ._-]+/u', '', pathinfo($name, PATHINFO_FILENAME));
        $base = trim(Str::limit($base, 80, ''), ' .') ?: 'file';

        return "{$base}.{$extension}";
    }
}

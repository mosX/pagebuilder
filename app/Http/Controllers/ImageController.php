<?php

namespace App\Http\Controllers;

use GdImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Resized copies of media library images: /img/{width}/{path under storage}.
 *
 * The copy is written to public/img/{width}/{path}, so the web server serves it directly
 * from then on and PHP only runs for the first request. Widths are limited to a fixed set,
 * so the cache cannot be filled with arbitrary sizes. Without GD (or for very large or
 * animated images) the original is served instead.
 */
class ImageController extends Controller
{
    public const WIDTHS = [320, 640, 960, 1280, 1920];

    private const MAX_PIXELS = 40_000_000;

    private const QUALITY = 82;

    public function __invoke(int $width, string $path): BinaryFileResponse|RedirectResponse
    {
        abort_unless(in_array($width, self::WIDTHS, true), 404);
        abort_unless($this->isSafePath($path), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $source = $disk->path($path);
        $target = public_path("img/{$width}/{$path}");

        if (! is_file($target) && ! $this->resize($source, $target, $width)) {
            return redirect($disk->url($path), 302);
        }

        return response()->file($target, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Only media library files of companies: no traversal, no hidden files, images only.
     */
    private function isSafePath(string $path): bool
    {
        return str_starts_with($path, 'tenants/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && preg_match('#^[A-Za-z0-9/_-]+\.(jpe?g|png|webp|gif)$#i', $path) === 1;
    }

    private function resize(string $source, string $target, int $width): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        $info = @getimagesize($source);

        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS || $this->isAnimatedGif($source, $info[2])) {
            return false;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if (! $image instanceof GdImage) {
            return false;
        }

        // Never upscale: a narrow original is cached as is.
        $resized = $info[0] > $width ? imagescale($image, $width, -1, IMG_BICUBIC) : $image;

        if (! $resized instanceof GdImage) {
            return false;
        }

        File::ensureDirectoryExists(dirname($target));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        return match ($info[2]) {
            IMAGETYPE_JPEG => imagejpeg($resized, $target, self::QUALITY),
            IMAGETYPE_PNG => imagepng($resized, $target, 8),
            IMAGETYPE_WEBP => imagewebp($resized, $target, self::QUALITY),
            // Only GIF is left: the source was decoded by one of the four readers above.
            default => imagegif($resized, $target),
        };
    }

    private function isAnimatedGif(string $source, int $type): bool
    {
        if ($type !== IMAGETYPE_GIF) {
            return false;
        }

        $content = (string) file_get_contents($source);

        // More than one image descriptor means more than one frame.
        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $content) > 1;
    }
}

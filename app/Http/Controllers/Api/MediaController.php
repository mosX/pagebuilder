<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\Media\MediaLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The current company's media library.
 */
class MediaController extends Controller
{
    public function __construct(private MediaLibrary $library) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();

        $media = Media::query()
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('original_name', 'like', "%{$search}%")
                ->orWhere('alt', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json($media->through(fn (Media $m) => $m->toApi()));
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:'.MediaLibrary::MAX_KILOBYTES,
                Rule::file()->types(['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']),
                'mimetypes:'.implode(',', MediaLibrary::MIME_TYPES),
            ],
        ], [
            'file.mimetypes' => 'Можно загружать только изображения JPG, PNG, WebP, GIF или AVIF.',
            'file.max' => 'Файл больше 10 МБ.',
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $media = $this->library->store($context->tenant(), $file, $request->user());

        return response()->json(['message' => 'Файл загружен.', 'media' => $media->toApi()], 201);
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $media->update($request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
        ]));

        return response()->json(['message' => 'Сохранено.', 'media' => $media->toApi()]);
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->library->delete($media);

        return response()->json(['message' => 'Файл удалён.']);
    }
}

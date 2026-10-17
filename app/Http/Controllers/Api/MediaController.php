<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use App\Support\Media\MediaLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // Pickers ask for one kind: images for pictures, documents for download links.
            ->when($request->string('kind')->value() === 'image', fn ($q) => $q->where('mime_type', 'like', 'image/%'))
            ->when($request->string('kind')->value() === 'document', fn ($q) => $q->where('mime_type', 'not like', 'image/%'))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json($media->through(fn (Media $m) => $m->toApi()));
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        // The type is checked by content, not by the name the browser sent.
        $request->validate([
            'file' => [
                'required', 'file', 'max:'.MediaLibrary::MAX_DOCUMENT_KILOBYTES,
                Rule::file()->types([...MediaLibrary::IMAGE_EXTENSIONS, ...MediaLibrary::DOCUMENT_EXTENSIONS]),
            ],
        ], [
            'file.mimes' => 'Можно загружать изображения (JPG, PNG, WebP, GIF, AVIF) и документы (PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV, ZIP).',
            'file.max' => 'Файл больше 20 МБ.',
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $isImage = str_starts_with((string) $file->getMimeType(), 'image/');

        if ($isImage && ! in_array($file->getMimeType(), MediaLibrary::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Этот формат изображений не поддерживается (SVG загружать нельзя).']);
        }

        if ($isImage && $file->getSize() > MediaLibrary::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => 'Изображение больше 10 МБ.']);
        }

        $media = $this->library->store($context->tenant(), $file, $request->user() instanceof User ? $request->user() : null);
        app(ActivityLog::class)->record('media.uploaded', $media->original_name);

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
        app(ActivityLog::class)->record('media.deleted', $media->original_name);

        return response()->json(['message' => 'Файл удалён.']);
    }
}

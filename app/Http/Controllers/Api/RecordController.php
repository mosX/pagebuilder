<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\RecordRevision;
use App\Support\Audit\ActivityLog;
use App\Support\Collections\RecordHistory;
use App\Support\Collections\RecordRepository;
use App\Support\Site\FormUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records of a collection, stored in the collection's own table.
 */
class RecordController extends Controller
{
    public function index(Request $request, Collection $collection): JsonResponse
    {
        $records = (new RecordRepository($collection))->paginate([
            'search' => $request->string('search')->value(),
            'sort' => $request->string('sort')->value(),
            'direction' => $request->string('direction')->value(),
            'per_page' => $request->integer('per_page', 20),
        ]);

        return response()->json($records);
    }

    public function options(Request $request, Collection $collection): JsonResponse
    {
        return response()->json(
            (new RecordRepository($collection))->options($request->string('search')->value()),
        );
    }

    public function show(Collection $collection, int $record): JsonResponse
    {
        return response()->json((new RecordRepository($collection))->find($record) ?? abort(404));
    }

    /**
     * Versions of a record, newest first.
     */
    public function revisions(Collection $collection, int $record): JsonResponse
    {
        abort_unless((new RecordRepository($collection))->exists($record), 404);

        $revisions = RecordRevision::query()
            ->where('collection_id', $collection->id)
            ->where('record_id', $record)
            ->with('user')
            ->latest('id')
            ->get()
            ->map(fn (RecordRevision $r) => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'author' => $r->user->name ?? $r->actor ?? 'Система',
                'changes' => $r->changes,
                'values' => $r->values,
            ]);

        return response()->json(['revisions' => $revisions]);
    }

    public function restore(Collection $collection, int $record, RecordRevision $revision, RecordHistory $history, ActivityLog $activity): JsonResponse
    {
        abort_unless($revision->record_id === $record, 404);

        $restored = $history->restore($collection, $revision);
        $activity->record('record.restored', "{$collection->label} #{$record}", ['revision' => $revision->id]);

        return response()->json(['message' => 'Версия восстановлена.', 'record' => $restored]);
    }

    /**
     * Downloads a file a visitor attached to a site form (private, admin only).
     */
    public function file(Collection $collection, int $record, string $field, FormUploads $uploads): StreamedResponse
    {
        $definition = $collection->field($field);
        $value = $definition && $definition->type === FieldType::File
            ? ((new RecordRepository($collection))->find($record)[$field] ?? null)
            : null;

        abort_unless(is_string($value) && $uploads->belongsTo($value, $collection->tenant_id), 404);

        return Storage::disk(FormUploads::DISK)->download($uploads->path($value), $uploads->name($value));
    }

    /**
     * Records of any collection that point at this one through a relation field
     * (e.g. an author's articles), up to 10 per field. Fields without links are listed
     * too, so the admin can add the first linked record.
     */
    public function backlinks(Collection $collection, int $record): JsonResponse
    {
        abort_unless((new RecordRepository($collection))->exists($record), 404);

        $groups = CollectionField::query()
            ->where('related_collection_id', $collection->id)
            ->with('collection')
            ->get()
            ->map(function (CollectionField $field) use ($record) {
                $repository = new RecordRepository($field->collection);
                $display = $repository->displayField();
                $page = $repository->paginate(['where' => [[$field->name, $record]], 'per_page' => 10]);

                return [
                    'collection' => $field->collection->only(['name', 'label']),
                    'field' => $field->only(['name', 'label']),
                    'total' => $page->total(),
                    'items' => collect($page->items())->map(fn (array $item) => [
                        'id' => $item['id'],
                        'label' => $display && is_scalar($item[$display->name] ?? null) && $item[$display->name] !== ''
                            ? (string) $item[$display->name]
                            : "#{$item['id']}",
                    ])->all(),
                ];
            })
            ->values();

        return response()->json($groups);
    }

    public function store(Request $request, Collection $collection): JsonResponse
    {
        $record = (new RecordRepository($collection))->create($request->all());
        app(ActivityLog::class)->record('record.created', "{$collection->label} #{$record['id']}");

        return response()->json(['message' => 'Запись создана.', 'record' => $record], 201);
    }

    public function update(Request $request, Collection $collection, int $record): JsonResponse
    {
        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        $updated = $repository->update($record, $request->all());
        app(ActivityLog::class)->record('record.updated', "{$collection->label} #{$record}", ['fields' => array_keys($request->all())]);

        return response()->json(['message' => 'Запись сохранена.', 'record' => $updated]);
    }

    public function destroy(Collection $collection, int $record): JsonResponse
    {
        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        $repository->delete($record);
        app(ActivityLog::class)->record('record.deleted', "{$collection->label} #{$record}");

        return response()->json(['message' => 'Запись удалена.']);
    }
}

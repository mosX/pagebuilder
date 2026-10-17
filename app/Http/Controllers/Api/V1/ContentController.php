<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SitePresenter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Headless API of a company (/api/v1), authenticated with an API key.
 * Read access to collections, records and published pages; write access to records
 * for keys created with that permission.
 */
class ContentController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private ActivityLog $activity,
    ) {}

    public function collections(): JsonResponse
    {
        $collections = Collection::query()->with('fields.relatedCollection')->orderBy('name')->get();

        return response()->json([
            'data' => $collections->map(fn (Collection $c) => [
                'name' => $c->name,
                'label' => $c->label,
                'description' => $c->description,
                'fields' => $c->fields->map(fn (CollectionField $f) => [
                    'name' => $f->name,
                    'label' => $f->label,
                    'type' => $f->type->value,
                    'required' => $f->is_required,
                    'unique' => $f->is_unique,
                    'choices' => $f->choices() ?: null,
                    'related_collection' => $f->relatedCollection?->name,
                ])->values(),
            ])->values(),
        ]);
    }

    public function index(Request $request, Collection $collection): JsonResponse
    {
        $filters = [];

        foreach ((array) $request->query('filter', []) as $field => $value) {
            if (is_string($field) && is_scalar($value)) {
                $filters[] = [$field, $value];
            }
        }

        return response()->json((new RecordRepository($collection))->paginate([
            'search' => $request->string('search')->value(),
            'sort' => $request->string('sort')->value(),
            'direction' => $request->string('direction')->value(),
            'per_page' => $request->integer('per_page', 20),
            'where' => $filters,
        ]));
    }

    public function show(Collection $collection, int $record): JsonResponse
    {
        return response()->json(['data' => (new RecordRepository($collection))->find($record) ?? abort(404)]);
    }

    public function store(Request $request, Collection $collection): JsonResponse
    {
        $this->ensureWritable($request);

        $record = (new RecordRepository($collection))->create($request->all());
        $this->activity->record('record.created', "{$collection->label} #{$record['id']}");

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, Collection $collection, int $record): JsonResponse
    {
        $this->ensureWritable($request);

        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        $updated = $repository->update($record, $request->all());
        $this->activity->record('record.updated', "{$collection->label} #{$record}", ['fields' => array_keys($request->all())]);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, Collection $collection, int $record): JsonResponse
    {
        $this->ensureWritable($request);

        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        $repository->delete($record);
        $this->activity->record('record.deleted', "{$collection->label} #{$record}");

        return response()->json(null, 204);
    }

    /**
     * A published page with its block tree and resolved data blocks.
     */
    public function page(SitePresenter $presenter, string $slug = PagesCollection::HOME_SLUG): JsonResponse
    {
        $tenant = $this->context->tenant();
        $page = $presenter->page($tenant, $slug, $tenant->siteUrl());

        abort_unless($page !== null, 404, 'Страница не найдена.');

        return response()->json(['data' => $page]);
    }

    private function ensureWritable(Request $request): void
    {
        $key = $request->attributes->get('api_key');

        abort_unless($key instanceof ApiKey && $key->can_write, 403, 'Ключ без права записи.');
    }
}

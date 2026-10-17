<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CollectionResource;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\SchemaManager;
use App\Support\Site\SiteLanguages;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Collections of the current company: the data types whose tables users build themselves.
 */
class CollectionController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private SchemaManager $schema,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $collections = Collection::query()->with('fields.relatedCollection')->orderBy('label')->get();

        foreach ($collections as $collection) {
            $collection->setAttribute('records_count', DB::table($collection->table_name)->count());
        }

        return CollectionResource::collection($collections);
    }

    public function show(Collection $collection): JsonResponse
    {
        $collection->load('fields.relatedCollection');

        return response()->json([
            'collection' => new CollectionResource($collection),
            'field_types' => FieldType::options(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'name' => [
                'required', 'string', 'regex:/^[a-z][a-z0-9_]{0,23}$/',
                Rule::unique('collections', 'name')->where('tenant_id', $tenant->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.regex' => 'Системное имя: латиница в нижнем регистре, цифры и _, до 24 символов, начинается с буквы.',
        ]);

        $collection = $this->schema->createCollection($tenant, $data);
        app(ActivityLog::class)->record('collection.created', $collection->label);

        return (new CollectionResource($collection->load('fields')))
            ->additional(['message' => "Коллекция «{$collection->label}» создана."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $displayable = $collection->fields
            ->filter(fn (CollectionField $f) => $f->type->canBeDisplayField())
            ->pluck('name')
            ->all();

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'display_field' => ['nullable', 'string', Rule::in($displayable)],
        ]);

        $collection->update($data);
        app(ActivityLog::class)->record('collection.updated', $collection->label);

        return response()->json([
            'message' => 'Коллекция сохранена.',
            'collection' => new CollectionResource($collection->load('fields.relatedCollection')),
        ]);
    }

    /**
     * Publishes the collection as a site section: /{site_path}/{slug field value}.
     */
    public function updateSite(Request $request, Collection $collection): JsonResponse
    {
        abort_if($collection->kind !== null, 422, 'Системную коллекцию нельзя опубликовать как раздел.');

        $slugFields = $collection->fields
            ->filter(fn (CollectionField $f) => $f->is_unique && in_array($f->type, [FieldType::String, FieldType::Integer], true))
            ->pluck('name')
            ->push('id')
            ->all();

        $booleanFields = $collection->fields
            ->filter(fn (CollectionField $f) => $f->type === FieldType::Boolean)
            ->pluck('name')
            ->all();

        $data = $request->validate([
            'site_path' => [
                'nullable', 'string', 'max:40', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/',
                Rule::unique('collections', 'site_path')->where('tenant_id', $collection->tenant_id)->ignore($collection->id),
                // Taken by service pages and language prefixes of the site.
                Rule::notIn(['search', 'account', ...array_keys(SiteLanguages::LOCALES)]),
            ],
            'site_slug_field' => ['nullable', 'required_with:site_path', Rule::in($slugFields)],
            'site_published_field' => ['nullable', Rule::in($booleanFields)],
        ], [
            'site_path.regex' => 'Путь: латиница в нижнем регистре, цифры и дефисы.',
            'site_path.not_in' => 'Этот путь занят служебными страницами сайта или языками.',
            'site_slug_field.in' => 'Адрес записи берётся из id или из уникального текстового/числового поля.',
        ]);

        $collection->forceFill([
            'site_path' => $data['site_path'] ?? null,
            'site_slug_field' => isset($data['site_path']) ? $data['site_slug_field'] : null,
            'site_published_field' => isset($data['site_path']) ? ($data['site_published_field'] ?? null) : null,
        ])->save();
        app(ActivityLog::class)->record('collection.site_updated', $collection->label, ['path' => $collection->site_path]);

        return response()->json([
            'message' => $collection->site_path ? 'Раздел сайта сохранён.' : 'Коллекция снята с сайта.',
            'collection' => new CollectionResource($collection->load('fields.relatedCollection')),
        ]);
    }

    /**
     * The block template every record page of the collection is rendered with.
     */
    public function updateTemplate(Request $request, Collection $collection, BlockRegistry $blocks): JsonResponse
    {
        $request->validate(['template' => ['present', 'array']]);

        $collections = Collection::query()->pluck('name')->map(fn (mixed $name) => (string) $name)->all();

        $collection->forceFill([
            'site_template' => $blocks->sanitize($request->input('template'), 'template', $collections),
        ])->save();

        return response()->json([
            'message' => 'Шаблон сохранён.',
            'template' => $collection->site_template,
        ]);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $this->schema->dropCollection($collection);
        app(ActivityLog::class)->record('collection.deleted', $collection->label);

        return response()->json(['message' => "Коллекция «{$collection->label}» удалена вместе с данными."]);
    }
}

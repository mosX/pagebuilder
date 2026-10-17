<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CollectionFieldResource;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Collections\SchemaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fields of a collection. Adding or removing a field changes the real table;
 * the system name and type are fixed after creation.
 */
class CollectionFieldController extends Controller
{
    public function __construct(private SchemaManager $schema) {}

    public function store(Request $request, Collection $collection): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'name' => [
                'required', 'string', 'regex:/^[a-z][a-z0-9_]{0,23}$/', 'not_regex:/_id$/',
                Rule::notIn(['id', 'created_at', 'updated_at']),
                Rule::unique('collection_fields', 'name')->where('collection_id', $collection->id),
            ],
            'type' => ['required', Rule::enum(FieldType::class)],
            'is_required' => ['boolean'],
            'is_unique' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.choices' => ['exclude_unless:type,select', 'required', 'array', 'min:1'],
            'options.choices.*' => ['string', 'max:255', 'distinct'],
            'options.scale' => ['exclude_unless:type,decimal', 'nullable', 'integer', 'between:0,8'],
            'related_collection_id' => [
                'exclude_unless:type,belongs_to,belongs_to_many', 'required', 'integer',
                Rule::exists('collections', 'id')->where('tenant_id', $collection->tenant_id),
            ],
        ], [
            'name.regex' => 'Системное имя: латиница в нижнем регистре, цифры и _, до 24 символов, начинается с буквы.',
            'name.not_regex' => 'Системное имя не может заканчиваться на _id.',
            'name.not_in' => 'Это имя зарезервировано.',
        ]);

        $type = FieldType::from($data['type']);

        $field = $this->schema->addField($collection, [
            'label' => $data['label'],
            'name' => $data['name'],
            'type' => $type,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_unique' => $type->supportsUnique() && (bool) ($data['is_unique'] ?? false),
            'options' => $this->optionsFor($type, $data['options'] ?? []),
            'related_collection_id' => $type->isRelation() ? $data['related_collection_id'] : null,
        ]);

        app(ActivityLog::class)->record('field.created', "{$collection->label} → {$field->label}", ['type' => $type->value]);

        return (new CollectionFieldResource($field->load('relatedCollection')))
            ->additional(['message' => "Поле «{$field->label}» добавлено."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Collection $collection, CollectionField $field): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'is_unique' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.choices' => [Rule::requiredIf($field->type === FieldType::Select), 'array', 'min:1'],
            'options.choices.*' => ['string', 'max:255', 'distinct'],
        ]);

        $options = $field->type === FieldType::Select
            ? [...($field->options ?? []), 'choices' => array_values($data['options']['choices'] ?? [])]
            : $field->options;

        $field->setRelation('collection', $collection);

        // System fields (e.g. page slug) are relied on by the platform: only their label is editable.
        $this->schema->updateField($field, $field->is_system ? ['label' => $data['label']] : [
            'label' => $data['label'],
            'is_required' => (bool) ($data['is_required'] ?? $field->is_required),
            'is_unique' => $field->type->supportsUnique() && (bool) ($data['is_unique'] ?? $field->is_unique),
            'options' => $options,
        ]);

        app(ActivityLog::class)->record('field.updated', "{$collection->label} → {$field->label}");

        return response()->json([
            'message' => "Поле «{$field->label}» сохранено.",
            'field' => new CollectionFieldResource($field->load('relatedCollection')),
        ]);
    }

    public function destroy(Collection $collection, CollectionField $field): JsonResponse
    {
        $field->setRelation('collection', $collection);
        $this->schema->dropField($field);
        app(ActivityLog::class)->record('field.deleted', "{$collection->label} → {$field->label}");

        return response()->json(['message' => "Поле «{$field->label}» удалено вместе с данными."]);
    }

    public function reorder(Request $request, Collection $collection): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('collection_fields', 'id')->where('collection_id', $collection->id)],
        ]);

        DB::transaction(function () use ($data, $collection) {
            foreach (array_values($data['ids']) as $position => $id) {
                $collection->fields()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['message' => 'Порядок полей сохранён.']);
    }

    /**
     * Keeps only the options that make sense for the type.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function optionsFor(FieldType $type, array $options): ?array
    {
        return match ($type) {
            FieldType::Select => ['choices' => array_values((array) ($options['choices'] ?? []))],
            FieldType::Decimal => ['scale' => (int) ($options['scale'] ?? 2)],
            default => null,
        };
    }
}

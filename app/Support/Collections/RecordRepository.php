<?php

namespace App\Support\Collections;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Blocks\BlockRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Rows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Reads and writes records of a collection's table with the query builder.
 *
 * The table name always comes from collection metadata (resolved inside the current tenant),
 * never from user input; field names are checked against the collection's fields.
 */
class RecordRepository
{
    public function __construct(private Collection $collection)
    {
        $collection->loadMissing('fields.relatedCollection.fields');
        $collection->fields->each->setRelation('collection', $collection);
    }

    /**
     * @param  array{search?: string|null, sort?: string|null, direction?: string|null, per_page?: int|null, page?: int|null, where?: array<int, array{0: string, 1: mixed}>}  $params
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $params = []): LengthAwarePaginator
    {
        $query = $this->query();

        foreach ($params['where'] ?? [] as [$name, $value]) {
            $field = $this->collection->field($name);

            // An unknown field must not silently widen the result.
            $field ? $this->applyFilter($query, $field, $value) : $query->whereRaw('1 = 0');
        }

        if ($search = trim((string) ($params['search'] ?? ''))) {
            $searchable = $this->collection->fields->filter(fn (CollectionField $f) => $f->type->isSearchable());

            $query->where(function (Builder $q) use ($searchable, $search) {
                $q->where('id', is_numeric($search) ? (int) $search : 0);

                foreach ($searchable as $field) {
                    $q->orWhere((string) $field->column(), 'like', "%{$search}%");
                }
            });
        }

        $sortable = ['id', 'created_at', 'updated_at', ...$this->collection->fields
            ->reject(fn (CollectionField $f) => $f->type->usesPivot())
            ->map(fn (CollectionField $f) => (string) $f->column())];

        $sort = in_array($params['sort'] ?? null, $sortable, true) ? $params['sort'] : 'id';
        $direction = ($params['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy((string) $sort, $direction)->paginate(
            perPage: min(max((int) ($params['per_page'] ?? 20), 1), 100),
            page: isset($params['page']) ? max(1, (int) $params['page']) : null,
        );

        $records = $this->present(collect($paginator->items()));
        $paginator->setCollection($records);

        /** @var LengthAwarePaginator<int, array<string, mixed>> $paginator */
        return $paginator;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->query()->where('id', $id)->first();

        return $row ? $this->present(collect([$row]))->first() : null;
    }

    /**
     * The first record matching all conditions ([field name or "id", value] pairs).
     *
     * @param  array<int, array{0: string, 1: mixed}>  $where
     * @return array<string, mixed>|null
     */
    public function firstWhere(array $where): ?array
    {
        $query = $this->query();

        foreach ($where as [$name, $value]) {
            $field = $name === 'id' ? null : $this->collection->field($name);

            match (true) {
                $name === 'id' => $query->where('id', is_numeric($value) ? (int) $value : 0),
                $field !== null => $this->applyFilter($query, $field, $value),
                default => $query->whereRaw('1 = 0'),
            };
        }

        $row = $query->orderBy('id')->first();

        return $row ? $this->present(collect([$row]))->first() : null;
    }

    /**
     * Equality filter that understands the field type: booleans, relations
     * (by id or by the related record's title), and plain values.
     */
    private function applyFilter(Builder $query, CollectionField $field, mixed $value): void
    {
        $value = is_string($value) ? trim($value) : $value;

        match ($field->type) {
            FieldType::Boolean => $query->where((string) $field->column(), filter_var($value, FILTER_VALIDATE_BOOLEAN)),
            FieldType::BelongsTo => $query->whereIn((string) $field->column(), $this->relatedIds($field, $value)),
            FieldType::BelongsToMany => $query->whereIn('id', DB::table($field->pivotTable())
                ->whereIn('target_id', $this->relatedIds($field, $value))
                ->select('source_id')),
            FieldType::Blocks => $query->whereRaw('1 = 0'),
            default => $query->where((string) $field->column(), is_scalar($value) ? (string) $value : ''),
        };
    }

    /**
     * Ids of related records given an id or a title.
     *
     * @return array<int, int>
     */
    private function relatedIds(CollectionField $field, mixed $value): array
    {
        $related = $field->relatedCollection;

        if (! $related || ! is_scalar($value) || $value === '') {
            return [];
        }

        if (is_numeric($value)) {
            return [(int) $value];
        }

        $display = (new self($related))->displayField();

        return $display
            ? DB::table($related->table_name)->where((string) $display->column(), (string) $value)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $data = $this->validate($input);

        $id = DB::transaction(function () use ($data) {
            $now = now();
            $id = (int) $this->query()->insertGetId([...$this->columns($data), 'created_at' => $now, 'updated_at' => $now]);
            $this->syncPivots($id, $data);

            return $id;
        });

        return (array) $this->find($id);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $data = $this->validate($input, $id);

        DB::transaction(function () use ($id, $data) {
            $this->query()->where('id', $id)->update([...$this->columns($data), 'updated_at' => now()]);
            $this->syncPivots($id, $data);
        });

        return (array) $this->find($id);
    }

    public function delete(int $id): void
    {
        // Pivot rows go away through their cascading foreign keys.
        $this->query()->where('id', $id)->delete();
    }

    public function exists(int $id): bool
    {
        return $this->query()->where('id', $id)->exists();
    }

    /**
     * Records as {id, label} pairs for relation pickers.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function options(?string $search = null, int $limit = 20): array
    {
        $display = $this->displayField();
        $query = $this->query()->select(['id', ...($display ? [$display->name] : [])]);

        if ($search) {
            $query->where(function (Builder $q) use ($search, $display) {
                $q->where('id', is_numeric($search) ? (int) $search : 0);

                if ($display) {
                    $q->orWhere($display->name, 'like', "%{$search}%");
                }
            });
        }

        return $query->orderBy($display->name ?? 'id')->limit($limit)->get()
            ->map(fn (stdClass $row) => ['id' => (int) $row->id, 'label' => $this->labelOf($row, $display)])
            ->values()
            ->all();
    }

    /**
     * Validation rules derived from field definitions. On update fields are optional (`sometimes`).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(?int $ignoreId = null): array
    {
        $rules = [];

        foreach ($this->collection->fields as $field) {
            $presence = $field->is_required ? 'required' : 'nullable';
            $base = $ignoreId ? ['sometimes', $presence] : [$presence];
            $column = (string) $field->column();
            $related = $field->relatedCollection->table_name ?? '';

            $typeRules = match ($field->type) {
                FieldType::String => ['string', 'max:255'],
                FieldType::Text => ['string', 'max:1000000'],
                FieldType::Email => ['string', 'email', 'max:255'],
                FieldType::Url => ['string', 'url', 'max:255'],
                FieldType::Image => ['string', 'max:1024', fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && $this->blocks()->safeUrl($value) === $value ? null : $fail('Укажите адрес изображения (https://… или /storage/…).')],
                FieldType::Integer => ['integer', 'between:-9007199254740991,9007199254740991'],
                FieldType::Decimal => ['numeric', 'decimal:0,'.$field->scale()],
                FieldType::Boolean => ['boolean'],
                FieldType::Date => ['date_format:Y-m-d'],
                FieldType::DateTime => ['date'],
                FieldType::Select => ['string', Rule::in($field->choices())],
                FieldType::BelongsTo => ['integer', Rule::exists($related, 'id')],
                FieldType::BelongsToMany, FieldType::Blocks => ['array'],
            };

            if ($field->is_unique && $field->type->supportsUnique()) {
                $typeRules[] = Rule::unique($this->collection->table_name, $column)->ignore($ignoreId);
            }

            $rules[$field->name] = [...$base, ...$typeRules];

            if ($field->type === FieldType::BelongsToMany) {
                $rules["{$field->name}.*"] = ['integer', 'distinct', Rule::exists($related, 'id')];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, ?int $ignoreId = null): array
    {
        $attributes = $this->collection->fields->mapWithKeys(fn (CollectionField $f) => [$f->name => $f->label])->all();

        return Validator::make($input, $this->rules($ignoreId), [], $attributes)->validate();
    }

    /**
     * Maps validated input to table columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [];

        foreach ($this->collection->fields as $field) {
            if ($field->type->usesPivot() || ! array_key_exists($field->name, $data)) {
                continue;
            }

            $value = $data[$field->name];

            $columns[(string) $field->column()] = match (true) {
                $value === null => null,
                $field->type === FieldType::Boolean => (bool) $value,
                $field->type === FieldType::DateTime => Carbon::parse(is_string($value) ? $value : '')->format('Y-m-d H:i:s'),
                $field->type === FieldType::Blocks => json_encode($this->blocks()->sanitize($value, $field->name, $this->collectionNames()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => $value,
            };
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncPivots(int $id, array $data): void
    {
        foreach ($this->collection->fields as $field) {
            if (! $field->type->usesPivot() || ! array_key_exists($field->name, $data)) {
                continue;
            }

            $ids = array_values(array_map('intval', (array) ($data[$field->name] ?? [])));
            DB::table($field->pivotTable())->where('source_id', $id)->delete();
            DB::table($field->pivotTable())->insert(array_map(
                fn (int $target, int $position) => ['source_id' => $id, 'target_id' => $target, 'position' => $position],
                $ids,
                array_keys($ids),
            ));
        }
    }

    /**
     * Turns raw rows into API records: casts scalars and resolves relations to {id, label}.
     *
     * @param  Rows<int, stdClass>  $rows
     * @return Rows<int, non-empty-array<string, mixed>>
     */
    private function present(Rows $rows): Rows
    {
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $relations = [];

        foreach ($this->collection->fields as $field) {
            if ($field->type === FieldType::BelongsTo) {
                $relations[$field->name] = $this->labels($field, $rows->pluck((string) $field->column())->filter()->unique()->all());
            } elseif ($field->type === FieldType::BelongsToMany) {
                $relations[$field->name] = $this->pivotLabels($field, $ids);
            }
        }

        return $rows->map(function (stdClass $row) use ($relations) {
            $record = [
                'id' => (int) $row->id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];

            foreach ($this->collection->fields as $field) {
                $record[$field->name] = match ($field->type) {
                    FieldType::BelongsTo => $relations[$field->name][(int) $row->{(string) $field->column()}] ?? null,
                    FieldType::BelongsToMany => $relations[$field->name][(int) $row->id] ?? [],
                    default => $this->cast($field, $row->{$field->name}),
                };
            }

            return $record;
        })->values();
    }

    private function cast(CollectionField $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            FieldType::Integer => (int) $value,
            FieldType::Boolean => (bool) $value,
            FieldType::Decimal => number_format((float) $value, $field->scale(), '.', ''),
            FieldType::Date => substr((string) $value, 0, 10),
            FieldType::Blocks => json_decode(is_string($value) ? $value : '[]', true) ?: [],
            default => $value,
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array{id: int, label: string}>
     */
    private function labels(CollectionField $field, array $ids): array
    {
        $related = $field->relatedCollection;

        if (! $related || $ids === []) {
            return [];
        }

        $display = (new self($related))->displayField();

        return DB::table($related->table_name)
            ->whereIn('id', $ids)
            ->get(['id', ...($display ? [$display->name] : [])])
            ->mapWithKeys(fn (stdClass $row) => [(int) $row->id => ['id' => (int) $row->id, 'label' => $this->labelOf($row, $display)]])
            ->all();
    }

    /**
     * @param  array<int, int>  $sourceIds
     * @return array<int, array<int, array{id: int, label: string}>>
     */
    private function pivotLabels(CollectionField $field, array $sourceIds): array
    {
        $related = $field->relatedCollection;

        if (! $related || $sourceIds === []) {
            return [];
        }

        $display = (new self($related))->displayField();
        $columns = ['p.source_id', 't.id', ...($display ? ["t.{$display->name}"] : [])];

        return DB::table($field->pivotTable().' as p')
            ->join($related->table_name.' as t', 't.id', '=', 'p.target_id')
            ->whereIn('p.source_id', $sourceIds)
            ->orderBy('p.position')
            ->get($columns)
            ->groupBy('source_id')
            ->map(fn (Rows $items) => $items
                ->map(fn (stdClass $row) => ['id' => (int) $row->id, 'label' => $this->labelOf($row, $display)])
                ->values()
                ->all())
            ->mapWithKeys(fn (array $items, int|string $sourceId) => [(int) $sourceId => $items])
            ->all();
    }

    /**
     * The field used as record title: the configured display field, else the first text-like field.
     */
    public function displayField(): ?CollectionField
    {
        $fields = $this->collection->fields->filter(fn (CollectionField $f) => $f->type->canBeDisplayField());

        return $fields->firstWhere('name', $this->collection->display_field) ?? $fields->first();
    }

    private function labelOf(stdClass $row, ?CollectionField $display): string
    {
        $value = $display ? $row->{$display->name} ?? null : null;

        return $value === null || $value === '' ? "#{$row->id}" : (string) $value;
    }

    private function blocks(): BlockRegistry
    {
        return app(BlockRegistry::class);
    }

    /**
     * Collections a block tree of this company may reference.
     *
     * @return array<int, string>
     */
    private function collectionNames(): array
    {
        return Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->collection->tenant_id)
            ->pluck('name')
            ->map(fn (mixed $name) => (string) $name)
            ->all();
    }

    private function query(): Builder
    {
        return DB::table($this->collection->table_name);
    }
}

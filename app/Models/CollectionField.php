<?php

namespace App\Models;

use App\Enums\FieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $collection_id
 * @property string $name
 * @property string $label
 * @property FieldType $type
 * @property array<string, mixed>|null $options
 * @property bool $is_required
 * @property bool $is_unique
 * @property bool $is_system
 * @property int $sort_order
 * @property int|null $related_collection_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection $collection
 * @property-read Collection|null $relatedCollection
 */
#[Fillable(['name', 'label', 'type', 'options', 'is_required', 'is_unique', 'sort_order', 'related_collection_id'])]
class CollectionField extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_unique' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * A field is only reachable through its collection, which was already resolved inside the tenant,
     * so the tenant scope is not needed here (and must not hide the relation when no tenant context
     * is set, e.g. in console commands). The same holds for the related collection.
     *
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class)->withoutGlobalScope('tenant');
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function relatedCollection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'related_collection_id')->withoutGlobalScope('tenant');
    }

    /**
     * The column in the collection table, or null for pivot-backed relations.
     */
    public function column(): ?string
    {
        return match (true) {
            $this->type === FieldType::BelongsTo => "{$this->name}_id",
            $this->type->usesPivot() => null,
            default => $this->name,
        };
    }

    public function pivotTable(): string
    {
        return "{$this->collection->table_name}__{$this->name}";
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function choices(): array
    {
        $choices = $this->option('choices', []);

        return is_array($choices) ? array_values(array_map('strval', $choices)) : [];
    }

    public function scale(): int
    {
        $scale = $this->option('scale', 2);

        return is_numeric($scale) ? (int) $scale : 2;
    }
}

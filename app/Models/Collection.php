<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user-defined data type of a tenant, backed by its own table.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property string $table_name
 * @property string|null $display_field
 * @property string|null $kind
 * @property string|null $site_path
 * @property string|null $site_slug_field
 * @property string|null $site_published_field
 * @property string|null $site_publish_at_field
 * @property array<int, array<string, mixed>>|null $site_template
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CollectionField> $fields
 */
#[Fillable(['name', 'label', 'description', 'display_field'])]
class Collection extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_template' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * @return HasMany<CollectionField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(CollectionField::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Fields of other collections that point at this one.
     *
     * @return HasMany<CollectionField, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(CollectionField::class, 'related_collection_id');
    }

    public function field(string $name): ?CollectionField
    {
        return $this->fields->firstWhere('name', $name);
    }

    public static function tableNameFor(int $tenantId, string $name): string
    {
        return "c{$tenantId}_{$name}";
    }
}

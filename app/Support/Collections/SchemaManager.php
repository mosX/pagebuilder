<?php

namespace App\Support\Collections;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keeps the real tables of collections in sync with their metadata.
 *
 * DDL is not transactional in MySQL, so every operation writes metadata and runs DDL
 * in an order that can be compensated: if the DDL fails, the metadata change is undone.
 * Index and foreign key names are explicit and based on field ids to stay within
 * MySQL's 64-character identifier limit.
 */
class SchemaManager
{
    /**
     * @param  array{name: string, label: string, description?: string|null}  $attributes
     */
    public function createCollection(Tenant $tenant, array $attributes): Collection
    {
        $collection = new Collection($attributes);
        $collection->tenant_id = $tenant->id;
        $collection->table_name = Collection::tableNameFor($tenant->id, $attributes['name']);

        if (Schema::hasTable($collection->table_name)) {
            throw ValidationException::withMessages(['name' => 'Таблица с таким именем уже существует.']);
        }

        Schema::create($collection->table_name, function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        try {
            $collection->save();
        } catch (Throwable $e) {
            Schema::dropIfExists($collection->table_name);

            throw $e;
        }

        return $collection;
    }

    public function dropCollection(Collection $collection): void
    {
        if ($collection->kind !== null) {
            throw ValidationException::withMessages(['collection' => 'Системную коллекцию удалить нельзя.']);
        }

        $incoming = $collection->incomingRelations()
            ->where('collection_id', '!=', $collection->id)
            ->with('collection')
            ->get();

        if ($incoming->isNotEmpty()) {
            $usages = $incoming->map(fn (CollectionField $f) => "{$f->collection->label} → {$f->label}")->implode(', ');

            throw ValidationException::withMessages([
                'collection' => "Коллекция используется в связях: {$usages}. Сначала удалите эти поля.",
            ]);
        }

        Schema::withoutForeignKeyConstraints(function () use ($collection) {
            foreach ($collection->fields as $field) {
                if ($field->type->usesPivot()) {
                    Schema::dropIfExists($field->pivotTable());
                }
            }

            Schema::dropIfExists($collection->table_name);
        });

        // Self-references would otherwise block deleting the metadata.
        $collection->fields()->update(['related_collection_id' => null]);
        $collection->delete();
    }

    /**
     * Drops every table of a tenant; used when the tenant itself is deleted.
     */
    public function dropTenant(Tenant $tenant): void
    {
        $collections = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->with('fields')
            ->get();

        Schema::withoutForeignKeyConstraints(function () use ($collections) {
            foreach ($collections as $collection) {
                foreach ($collection->fields as $field) {
                    if ($field->type->usesPivot()) {
                        Schema::dropIfExists($field->pivotTable());
                    }
                }

                Schema::dropIfExists($collection->table_name);
            }
        });

        CollectionField::whereIn('collection_id', $collections->modelKeys())->update(['related_collection_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addField(Collection $collection, array $attributes): CollectionField
    {
        /** @var CollectionField $field */
        $field = $collection->fields()->make($attributes);
        $field->sort_order = (int) $collection->fields()->max('sort_order') + 1;
        $field->save();
        $field->setRelation('collection', $collection);

        try {
            $field->type->usesPivot()
                ? $this->createPivot($field)
                : Schema::table($collection->table_name, fn (Blueprint $table) => $this->addColumn($table, $field));
        } catch (Throwable $e) {
            $field->delete();

            throw $e;
        }

        $collection->unsetRelation('fields');

        return $field;
    }

    /**
     * Applies changes that do not touch the column type: label, required flag, options and the unique index.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateField(CollectionField $field, array $attributes): CollectionField
    {
        $wasUnique = $field->is_unique;
        $field->fill($attributes);
        $column = $field->column();

        if ($column && $wasUnique !== $field->is_unique) {
            try {
                Schema::table($field->collection->table_name, function (Blueprint $table) use ($field, $column) {
                    $field->is_unique
                        ? $table->unique($column, $this->uniqueIndexName($field))
                        : $table->dropUnique($this->uniqueIndexName($field));
                });
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'is_unique' => 'Не удалось включить уникальность: в данных уже есть повторяющиеся значения.',
                ]);
            }
        }

        $field->save();

        return $field;
    }

    public function dropField(CollectionField $field): void
    {
        if ($field->is_system) {
            throw ValidationException::withMessages(['field' => 'Системное поле удалить нельзя.']);
        }

        $collection = $field->collection;

        if ($collection->display_field === $field->name) {
            $collection->update(['display_field' => null]);
        }

        if ($field->type->usesPivot()) {
            Schema::dropIfExists($field->pivotTable());
        } else {
            Schema::table($collection->table_name, function (Blueprint $table) use ($field) {
                if ($field->type === FieldType::BelongsTo) {
                    // SQLite (tests) can only drop a foreign key by its columns.
                    Schema::getConnection()->getDriverName() === 'sqlite'
                        ? $table->dropForeign([(string) $field->column()])
                        : $table->dropForeign($this->foreignKeyName($field));
                }

                if ($field->is_unique) {
                    $table->dropUnique($this->uniqueIndexName($field));
                }

                $table->dropColumn((string) $field->column());
            });
        }

        $field->delete();
    }

    /**
     * Columns are always nullable: "required" is enforced by validation, which keeps
     * adding a required field to a table with existing rows safe.
     */
    private function addColumn(Blueprint $table, CollectionField $field): void
    {
        $name = (string) $field->column();

        $column = match ($field->type) {
            FieldType::String, FieldType::Email, FieldType::Url, FieldType::Select => $table->string($name),
            FieldType::Text => $table->longText($name),
            FieldType::Image => $table->string($name, 1024),
            FieldType::Integer => $table->bigInteger($name),
            FieldType::Decimal => $table->decimal($name, 20, $field->scale()),
            FieldType::Boolean => $table->boolean($name),
            FieldType::Date => $table->date($name),
            FieldType::DateTime => $table->dateTime($name),
            FieldType::BelongsTo => $table->unsignedBigInteger($name),
            FieldType::Blocks => $table->json($name),
            FieldType::BelongsToMany => throw new \LogicException('Pivot relations have no column.'),
        };

        $column->nullable();

        if ($field->type === FieldType::BelongsTo) {
            $table->foreign($name, $this->foreignKeyName($field))
                ->references('id')
                ->on($this->relatedTable($field))
                ->nullOnDelete();
        }

        if ($field->is_unique && $field->type->supportsUnique()) {
            $table->unique($name, $this->uniqueIndexName($field));
        }
    }

    private function createPivot(CollectionField $field): void
    {
        $pivot = $field->pivotTable();

        Schema::create($pivot, function (Blueprint $table) use ($field, $pivot) {
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->unsignedInteger('position')->default(0);
            $table->primary(['source_id', 'target_id'], "{$pivot}_pk");

            $table->foreign('source_id', "{$pivot}_s_fk")
                ->references('id')->on($field->collection->table_name)->cascadeOnDelete();
            $table->foreign('target_id', "{$pivot}_t_fk")
                ->references('id')->on($this->relatedTable($field))->cascadeOnDelete();
        });
    }

    private function relatedTable(CollectionField $field): string
    {
        return $field->relatedCollection->table_name
            ?? throw new \LogicException("Field {$field->name} has no related collection.");
    }

    private function uniqueIndexName(CollectionField $field): string
    {
        return "{$field->collection->table_name}_f{$field->id}_uq";
    }

    private function foreignKeyName(CollectionField $field): string
    {
        return "{$field->collection->table_name}_f{$field->id}_fk";
    }
}

<?php

namespace App\Support\Collections;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Exports the structure of a company's collections to JSON and imports it into another
 * company or environment (e.g. from dev to production). Data is not transferred.
 *
 * Import is additive: existing collections and fields are left untouched and reported.
 * Relations are created after all collections exist, so their order does not matter.
 */
class SchemaTransfer
{
    public const VERSION = 1;

    private const NAME = '/^[a-z][a-z0-9_]{0,23}$/';

    public function __construct(
        private SchemaManager $schema,
        private BlockRegistry $blocks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(Tenant $tenant): array
    {
        $collections = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereNull('kind')
            ->with('fields.relatedCollection')
            ->orderBy('id')
            ->get();

        return [
            'format' => 'pagebuilder-schema',
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'collections' => $collections->map(fn (Collection $c) => [
                'name' => $c->name,
                'label' => $c->label,
                'description' => $c->description,
                'display_field' => $c->display_field,
                'site' => $c->site_path ? [
                    'path' => $c->site_path,
                    'slug_field' => $c->site_slug_field,
                    'published_field' => $c->site_published_field,
                    'publish_at_field' => $c->site_publish_at_field,
                    'template' => $c->site_template ?? [],
                ] : null,
                'fields' => $c->fields->map(fn (CollectionField $f) => [
                    'name' => $f->name,
                    'label' => $f->label,
                    'type' => $f->type->value,
                    'options' => $f->options,
                    'is_required' => $f->is_required,
                    'is_unique' => $f->is_unique,
                    'related_collection' => $f->relatedCollection?->name,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function import(Tenant $tenant, array $data): array
    {
        if (($data['format'] ?? null) !== 'pagebuilder-schema' || ! is_array($data['collections'] ?? null)) {
            throw ValidationException::withMessages(['schema' => 'Это не файл схемы конструктора.']);
        }

        $report = ['created' => [], 'skipped' => [], 'errors' => []];
        /** @var array<string, Collection> $created */
        $created = [];
        $definitions = array_values(array_filter($data['collections'], 'is_array'));

        // 1. Collections.
        foreach ($definitions as $definition) {
            $name = is_string($definition['name'] ?? null) ? $definition['name'] : '';
            $label = is_string($definition['label'] ?? null) && $definition['label'] !== '' ? $definition['label'] : $name;

            if (! preg_match(self::NAME, $name)) {
                $report['errors'][] = "Коллекция «{$name}»: недопустимое системное имя.";

                continue;
            }

            if ($this->find($tenant, $name)) {
                $report['skipped'][] = "Коллекция «{$label}» уже есть.";

                continue;
            }

            try {
                $created[$name] = $this->schema->createCollection($tenant, [
                    'name' => $name,
                    'label' => mb_substr($label, 0, 255),
                    'description' => is_string($definition['description'] ?? null) ? mb_substr($definition['description'], 0, 1000) : null,
                ]);
                $report['created'][] = "Коллекция «{$label}»";
            } catch (Throwable $e) {
                $report['errors'][] = "Коллекция «{$label}»: {$this->message($e)}";
            }
        }

        // 2. Plain fields, then 3. relations (their targets exist by now).
        foreach ([false, true] as $relations) {
            foreach ($definitions as $definition) {
                $collection = $this->find($tenant, is_string($definition['name'] ?? null) ? $definition['name'] : '');

                foreach ($collection && is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
                    $type = is_array($field) ? FieldType::tryFrom((string) ($field['type'] ?? '')) : null;

                    if ($type && $type->isRelation() === $relations) {
                        $this->importField($tenant, $collection, $field, $type, $report);
                    }
                }
            }
        }

        // 4. Settings of the collections created by this import.
        foreach ($definitions as $definition) {
            $collection = $created[$definition['name'] ?? ''] ?? null;

            if ($collection) {
                $this->importSettings($tenant, $collection->fresh() ?? $collection, $definition, $report);
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $report
     */
    private function importField(Tenant $tenant, Collection $collection, array $field, FieldType $type, array &$report): void
    {
        $name = is_string($field['name'] ?? null) ? $field['name'] : '';
        $title = "{$collection->label} → {$name}";

        if (! preg_match(self::NAME, $name) || str_ends_with($name, '_id') || in_array($name, ['id', 'created_at', 'updated_at'], true)) {
            $report['errors'][] = "Поле {$title}: недопустимое системное имя.";

            return;
        }

        if ($collection->fields()->where('name', $name)->exists()) {
            $report['skipped'][] = "Поле {$title} уже есть.";

            return;
        }

        $related = null;

        if ($type->isRelation()) {
            $related = $this->find($tenant, is_string($field['related_collection'] ?? null) ? $field['related_collection'] : '');

            if (! $related) {
                $report['errors'][] = "Поле {$title}: нет связанной коллекции «{$field['related_collection']}».";

                return;
            }
        }

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];

        if ($type === FieldType::Select && empty($options['choices'])) {
            $report['errors'][] = "Поле {$title}: у списка нет вариантов.";

            return;
        }

        try {
            $this->schema->addField($collection, [
                'name' => $name,
                'label' => mb_substr(is_string($field['label'] ?? null) ? $field['label'] : $name, 0, 255),
                'type' => $type,
                'options' => match ($type) {
                    FieldType::Select => ['choices' => array_values(array_map('strval', (array) $options['choices']))],
                    FieldType::Decimal => ['scale' => min(8, max(0, (int) ($options['scale'] ?? 2)))],
                    default => null,
                },
                'is_required' => (bool) ($field['is_required'] ?? false),
                'is_unique' => $type->supportsUnique() && (bool) ($field['is_unique'] ?? false),
                'related_collection_id' => $related?->id,
            ]);
            $collection->unsetRelation('fields');
            $report['created'][] = "Поле {$title}";
        } catch (Throwable $e) {
            $report['errors'][] = "Поле {$title}: {$this->message($e)}";
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $report
     */
    private function importSettings(Tenant $tenant, Collection $collection, array $definition, array &$report): void
    {
        $names = $collection->fields->pluck('name')->all();
        $display = $definition['display_field'] ?? null;
        $site = is_array($definition['site'] ?? null) ? $definition['site'] : null;
        $path = is_string($site['path'] ?? null) ? $site['path'] : null;
        $pathTaken = $path && Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('site_path', $path)->exists();

        if ($pathTaken) {
            $report['errors'][] = "Коллекция «{$collection->label}»: раздел сайта /{$path} уже занят.";
        }

        $publish = $path && ! $pathTaken && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $path);
        $slugField = $site['slug_field'] ?? 'id';
        $publishedField = $site['published_field'] ?? null;
        $publishAtField = $site['publish_at_field'] ?? null;
        $collections = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->pluck('name')->map(fn (mixed $n) => (string) $n)->all();

        $collection->forceFill([
            'display_field' => in_array($display, $names, true) ? $display : null,
            'site_path' => $publish ? $path : null,
            'site_slug_field' => $publish ? ($slugField === 'id' || in_array($slugField, $names, true) ? $slugField : 'id') : null,
            'site_published_field' => $publish && in_array($publishedField, $names, true) ? $publishedField : null,
            'site_publish_at_field' => $publish && in_array($publishAtField, $names, true) ? $publishAtField : null,
            'site_template' => $publish ? $this->blocks->sanitize($site['template'] ?? [], 'template', $collections) : null,
        ])->save();
    }

    private function find(Tenant $tenant, string $name): ?Collection
    {
        return Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', $name)->first();
    }

    private function message(Throwable $e): string
    {
        return $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : 'ошибка базы данных';
    }
}

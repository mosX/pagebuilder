<?php

namespace App\Support\Blocks;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Collections\RecordRepository;
use App\Support\Site\RecordUrls;
use Illuminate\Support\Str;

/**
 * Fills `data` for blocks that show company data (block.json "data" key),
 * both for the editor preview and for the public site.
 */
class BlockDataResolver
{
    public function __construct(
        private BlockRegistry $registry,
        private RecordUrls $urls,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function resolveTree(RenderContext $context, array $nodes): array
    {
        return array_map(function (array $node) use ($context) {
            $data = $this->resolve($context, $node);

            if ($data !== null) {
                $node['data'] = $data;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->resolveTree($context, $node['children']);
            }

            return $node;
        }, $nodes);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function resolve(RenderContext $context, array $node): mixed
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $props = (array) ($node['props'] ?? []);

        return match ($this->registry->get($type)['data'] ?? null) {
            'records' => $this->records($context, $props),
            'record_field' => $this->recordField($context, $props),
            default => null,
        };
    }

    /**
     * Records of a collection as cards: a title (the collection's display field), an optional
     * subtitle and image from fields the editor picked explicitly (so no other data is exposed),
     * and a link when the collection is published as a site section.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function records(RenderContext $context, array $props): array
    {
        $collection = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $context->tenant->id)
            ->where('name', is_string($props['collection'] ?? null) ? $props['collection'] : '')
            ->first();

        if (! $collection) {
            return ['collection' => null, 'items' => [], 'page' => 1, 'last_page' => 1];
        }

        $repository = new RecordRepository($collection);
        $display = $repository->displayField();
        $subtitle = $this->field($collection, $props['subtitle_field'] ?? null);
        $image = $this->field($collection, $props['image_field'] ?? null, FieldType::Image);
        $filter = $this->field($collection, $props['filter_field'] ?? null);

        $paginate = (bool) ($props['paginate'] ?? false);
        $published = $collection->site_published_field;

        $page = $repository->paginate([
            'per_page' => is_numeric($props['limit'] ?? null) ? (int) $props['limit'] : 6,
            'page' => $paginate ? $context->page : 1,
            ...$this->sort($props, $display),
            'where' => array_filter([
                // Unpublished records never reach the site.
                $published ? [$published, true] : null,
                $filter ? [$filter->name, $props['filter_value'] ?? ''] : null,
            ]),
        ]);

        $items = [];

        foreach ($page->items() as $record) {
            $items[] = [
                'id' => (int) $record['id'],
                'title' => $display ? ($this->text($record[$display->name] ?? null) ?? "#{$record['id']}") : "#{$record['id']}",
                'subtitle' => $subtitle ? $this->text($record[$subtitle->name] ?? null) : null,
                'image' => $image && is_string($record[$image->name] ?? null) ? $record[$image->name] : null,
                'url' => $this->urls->record($collection, $record, $context->baseUrl),
            ];
        }

        return [
            'collection' => $collection->label,
            'items' => $items,
            'page' => $paginate ? $page->currentPage() : 1,
            'last_page' => $paginate ? $page->lastPage() : 1,
        ];
    }

    /**
     * The value of a field of the record a template is rendered for.
     *
     * @param  array<string, mixed>  $props
     * @return array{type: string|null, value: string|null, items: list<array{label: string, url: string|null}>}
     */
    private function recordField(RenderContext $context, array $props): array
    {
        $field = $context->collection ? $this->field($context->collection, $props['field'] ?? null) : null;
        $value = $field && $context->record ? ($context->record[$field->name] ?? null) : null;

        if (! $field || $value === null || $value === '' || $value === []) {
            return ['type' => $field?->type->value, 'value' => null, 'items' => []];
        }

        // Relations become links when the related collection has record pages.
        $related = match ($field->type) {
            FieldType::BelongsTo => [$value],
            FieldType::BelongsToMany => (array) $value,
            default => [],
        };

        $items = [];

        foreach ($related as $item) {
            if (is_array($item) && $field->relatedCollection) {
                $items[] = [
                    'label' => (string) ($item['label'] ?? ''),
                    'url' => $this->urls->recordById($field->relatedCollection, (int) ($item['id'] ?? 0), $context->baseUrl),
                ];
            }
        }

        return [
            'type' => $field->type->value,
            'value' => $field->type === FieldType::Text ? Str::limit((string) $value, 100000, '') : $this->text($value),
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array{sort: string, direction: string}
     */
    private function sort(array $props, ?CollectionField $display): array
    {
        return match ($props['sort'] ?? 'newest') {
            'oldest' => ['sort' => 'id', 'direction' => 'asc'],
            'title' => ['sort' => $display ? (string) $display->column() : 'id', 'direction' => 'asc'],
            default => ['sort' => 'id', 'direction' => 'desc'],
        };
    }

    /**
     * A field the editor picked, if it exists (and has the expected type).
     */
    private function field(Collection $collection, mixed $name, ?FieldType $type = null): ?CollectionField
    {
        return $collection->fields->first(fn (CollectionField $f) => $f->name === $name
            && $f->type !== FieldType::Blocks
            && ($type === null || $f->type === $type));
    }

    /**
     * Presents any record value as plain text.
     */
    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => null,
            is_bool($value) => $value ? 'Да' : 'Нет',
            is_array($value) && isset($value['label']) => (string) $value['label'],
            is_array($value) => implode(', ', array_map(fn ($v) => is_array($v) ? (string) ($v['label'] ?? '') : '', $value)),
            default => Str::limit(is_scalar($value) ? (string) $value : '', 200),
        };
    }
}

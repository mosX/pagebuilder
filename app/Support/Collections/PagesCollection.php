<?php

namespace App\Support\Collections;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The company's pages: a system collection (kind "pages") with a block-tree field.
 * Every company gets one; the public site is served from it.
 */
class PagesCollection
{
    public const KIND = 'pages';

    public const HOME_SLUG = 'home';

    public function __construct(private SchemaManager $schema) {}

    public function find(Tenant $tenant): ?Collection
    {
        return Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('kind', self::KIND)
            ->first();
    }

    /**
     * Creates the pages collection with a starter home page, unless it exists already.
     */
    public function ensure(Tenant $tenant): Collection
    {
        if ($existing = $this->find($tenant)) {
            $this->addMissingFields($existing);

            return $existing;
        }

        $taken = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'pages')->exists();

        $collection = $this->schema->createCollection($tenant, [
            'name' => $taken ? 'site_pages' : 'pages',
            'label' => 'Страницы',
            'description' => 'Страницы сайта компании',
        ]);

        $this->addMissingFields($collection);
        $collection->forceFill(['kind' => self::KIND, 'display_field' => 'title'])->save();
        $collection->unsetRelation('fields');

        (new RecordRepository($collection))->create([
            'title' => 'Главная',
            'slug' => self::HOME_SLUG,
            'is_published' => true,
            'content' => $this->starterContent($tenant),
        ]);

        return $collection;
    }

    /**
     * System fields of a pages collection. New ones are added to existing collections by ensure().
     *
     * @return list<array<string, mixed>>
     */
    private function systemFields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String, 'is_required' => true],
            ['name' => 'slug', 'label' => 'Адрес (slug)', 'type' => FieldType::String, 'is_required' => true, 'is_unique' => true],
            ['name' => 'description', 'label' => 'Описание (SEO)', 'type' => FieldType::Text],
            ['name' => 'content', 'label' => 'Содержимое', 'type' => FieldType::Blocks],
            ['name' => 'is_published', 'label' => 'Опубликована', 'type' => FieldType::Boolean],
        ];
    }

    private function addMissingFields(Collection $collection): void
    {
        $existing = $collection->fields()->pluck('name')->all();

        foreach ($this->systemFields() as $attributes) {
            if (! in_array($attributes['name'], $existing, true)) {
                $field = $this->schema->addField($collection, $attributes);
                $field->forceFill(['is_system' => true])->save();
            }
        }

        $collection->unsetRelation('fields');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function starterContent(Tenant $tenant): array
    {
        $node = fn (string $type, array $props, ?array $children = null) => array_filter([
            'id' => Str::random(10),
            'type' => $type,
            'props' => $props,
            'children' => $children,
        ], fn ($value) => $value !== null);

        return [
            $node('hero', [
                'title' => $tenant->name,
                'subtitle' => 'Это стартовая страница. Отредактируйте её в конструкторе блоков.',
                'button_label' => 'Связаться',
                'button_url' => '#contacts',
            ]),
            $node('section', ['padding' => 'large'], [
                $node('heading', ['text' => 'О нас', 'level' => 'h2', 'align' => 'center']),
                $node('text', [
                    'text' => "Страница состоит из блоков: секций, колонок, заголовков, текста, изображений и кнопок.\n\nБлок «Список записей» выводит данные из любой коллекции компании.",
                    'align' => 'center',
                ]),
            ]),
        ];
    }
}

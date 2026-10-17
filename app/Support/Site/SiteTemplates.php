<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CustomTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\DB;

/**
 * Starter sites for new companies: pages, collections with sample records, site sections,
 * menu and theme. Applied once, right after the company is created, so a new site has
 * real structure to edit instead of a blank page.
 */
class SiteTemplates
{
    public const BLANK = 'blank';

    public function __construct(
        private SchemaManager $schema,
        private PagesCollection $pages,
        private SiteDesign $design,
        private BlockRegistry $blocks,
        private SiteSnapshots $snapshots,
    ) {}

    /**
     * Built-in starters plus sites saved as templates that the user may use.
     *
     * @return list<array{key: string, label: string, description: string, custom?: bool}>
     */
    public function all(?User $user = null): array
    {
        $custom = CustomTemplate::query()->availableTo($user)->latest('id')->get()
            ->map(fn (CustomTemplate $t) => ['key' => $t->key(), 'label' => $t->name, 'description' => (string) $t->description, 'custom' => true])
            ->values()
            ->all();

        return [...$this->builtIn(), ...$custom];
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    private function builtIn(): array
    {
        return [
            ['key' => self::BLANK, 'label' => 'Пустой сайт', 'description' => 'Одна главная страница — всё остальное соберёте сами.'],
            ['key' => 'business', 'label' => 'Визитка', 'description' => 'Главная, услуги с ценами, отзывы, FAQ и форма заявки.'],
            ['key' => 'blog', 'label' => 'Блог', 'description' => 'Статьи с рубриками и отдельной страницей каждой статьи.'],
            ['key' => 'catalog', 'label' => 'Каталог', 'description' => 'Товары с ценами и фото, страница товара и форма заказа.'],
        ];
    }

    /**
     * @return list<string>
     */
    public function keys(?User $user = null): array
    {
        return array_column($this->all($user), 'key');
    }

    public function apply(Tenant $tenant, string $key): void
    {
        if (str_starts_with($key, CustomTemplate::KEY_PREFIX)) {
            $this->snapshots->apply($tenant, CustomTemplate::query()->findOrFail((int) substr($key, strlen(CustomTemplate::KEY_PREFIX))));

            return;
        }

        $pages = $this->pages->ensure($tenant);

        match ($key) {
            'business' => $this->business($tenant, $pages),
            'blog' => $this->blog($tenant, $pages),
            'catalog' => $this->catalog($tenant, $pages),
            default => null,
        };
    }

    private function business(Tenant $tenant, Collection $pages): void
    {
        $this->collection($tenant, 'requests', 'Заявки', 'Заявки с сайта', [
            ['name', 'Имя', FieldType::String, true],
            ['phone', 'Телефон', FieldType::String],
            ['email', 'Email', FieldType::Email],
            ['message', 'Сообщение', FieldType::Text],
            ['status', 'Статус', FieldType::Select, false, ['Новая', 'В работе', 'Закрыта']],
        ]);

        $this->home($pages, [
            $this->node('hero', [
                'title' => $tenant->name,
                'subtitle' => 'Коротко о том, чем вы помогаете клиентам. Отредактируйте этот текст в конструкторе.',
                'button_label' => 'Оставить заявку',
                'button_url' => '/contacts',
            ]),
            $this->section([$this->node('features', ['title' => 'Почему выбирают нас'])]),
            $this->section([$this->node('testimonials', ['title' => 'Отзывы клиентов'])], '#f9fafb'),
            $this->section([
                $this->node('heading', ['text' => 'Готовы начать?', 'level' => 'h2', 'align' => 'center']),
                $this->node('button', ['label' => 'Связаться с нами', 'url' => '/contacts', 'align' => 'center']),
            ]),
        ]);

        $this->page($pages, 'Услуги', 'services', 'Услуги и цены', [
            $this->section([$this->node('heading', ['text' => 'Услуги и цены', 'level' => 'h1'])], '', 'medium'),
            $this->section([$this->node('pricing', ['title' => ''])]),
            $this->section([$this->node('faq', ['title' => 'Частые вопросы'])], '#f9fafb'),
        ]);

        $this->page($pages, 'О нас', 'about', 'О компании', [
            $this->section([
                $this->node('heading', ['text' => 'О нас', 'level' => 'h1']),
                $this->node('text', ['text' => "Расскажите историю компании, кто в команде и чем вы отличаетесь.\n\nЭтот текст редактируется в конструкторе блоков."]),
            ], '', 'large', 'narrow'),
        ]);

        $this->page($pages, 'Контакты', 'contacts', 'Как с нами связаться', [
            $this->section([
                $this->node('heading', ['text' => 'Контакты', 'level' => 'h1']),
                $this->node('text', ['text' => "Телефон: +7 (000) 000-00-00\n\nE-mail: hello@example.com"]),
                $this->node('form', [
                    'collection' => 'requests',
                    'fields' => ['name', 'phone', 'email', 'message'],
                    'title' => 'Оставьте заявку',
                    'submit_label' => 'Отправить',
                    'success_message' => 'Спасибо! Мы свяжемся с вами в ближайшее время.',
                ]),
            ], '', 'large', 'narrow'),
        ]);

        $this->menu($tenant, ['home' => 'Главная', 'services' => 'Услуги', 'about' => 'О нас', 'contacts' => 'Контакты']);
    }

    private function blog(Tenant $tenant, Collection $pages): void
    {
        $categories = $this->collection($tenant, 'categories', 'Рубрики', 'Рубрики статей', [
            ['title', 'Название', FieldType::String, true],
        ]);
        $posts = $this->collection($tenant, 'posts', 'Статьи', 'Статьи блога', [
            ['title', 'Заголовок', FieldType::String, true],
            ['slug', 'Адрес (slug)', FieldType::String, true, null, true],
            ['excerpt', 'Анонс', FieldType::Text],
            ['body', 'Текст', FieldType::Text],
            ['cover', 'Обложка', FieldType::Image],
            ['category', 'Рубрика', FieldType::BelongsTo, false, null, false, $categories],
            ['published_at', 'Дата публикации', FieldType::Date],
            ['is_published', 'Опубликована', FieldType::Boolean],
        ]);

        $news = (new RecordRepository($categories))->create(['title' => 'Новости']);
        $guides = (new RecordRepository($categories))->create(['title' => 'Советы']);
        $repository = new RecordRepository($posts);

        foreach ([
            ['Добро пожаловать в блог', 'welcome', 'Первая запись нового блога.', $news['id']],
            ['Как редактировать статьи', 'how-to-edit', 'Статьи — это записи коллекции «Статьи»: откройте её в админке.', $guides['id']],
        ] as [$title, $slug, $excerpt, $category]) {
            $repository->create([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'body' => "{$excerpt}\n\nТекст статьи редактируется в карточке записи, а вид страницы статьи — в шаблоне коллекции.",
                'category' => $category,
                'published_at' => now()->toDateString(),
                'is_published' => true,
            ]);
        }

        $this->publish($posts, 'blog', 'slug', 'is_published', [
            $this->section([
                $this->node('record_field', ['field' => 'category', 'style' => 'tags']),
                $this->node('record_field', ['field' => 'title', 'style' => 'h1']),
                $this->node('record_field', ['field' => 'published_at', 'style' => 'meta']),
                $this->node('record_field', ['field' => 'cover', 'style' => 'image']),
                $this->node('record_field', ['field' => 'body', 'style' => 'text']),
            ], '', 'large', 'narrow'),
        ]);

        $list = fn (bool $paginate) => $this->node('records', [
            'collection' => 'posts',
            'title' => $paginate ? 'Все статьи' : 'Свежие статьи',
            'subtitle_field' => 'excerpt',
            'image_field' => 'cover',
            'sort' => 'newest',
            'limit' => $paginate ? 12 : 3,
            'paginate' => $paginate,
            'columns' => '3',
        ]);

        $this->home($pages, [
            $this->node('hero', ['title' => $tenant->name, 'subtitle' => 'О чём этот блог — в паре предложений.', 'button_label' => 'Читать', 'button_url' => '/blog']),
            $this->section([$list(false)]),
        ]);
        $this->page($pages, 'Блог', 'blog', 'Все статьи блога', [$this->section([$list(true)])]);
        $this->page($pages, 'Об авторе', 'about', 'Об авторе блога', [
            $this->section([
                $this->node('heading', ['text' => 'Об авторе', 'level' => 'h1']),
                $this->node('text', ['text' => 'Несколько слов о себе и о том, о чём вы пишете.']),
            ], '', 'large', 'narrow'),
        ]);

        $this->menu($tenant, ['home' => 'Главная', 'blog' => 'Блог', 'about' => 'Об авторе']);
    }

    private function catalog(Tenant $tenant, Collection $pages): void
    {
        $products = $this->collection($tenant, 'products', 'Товары', 'Каталог товаров', [
            ['name', 'Название', FieldType::String, true],
            ['slug', 'Адрес (slug)', FieldType::String, true, null, true],
            ['price', 'Цена', FieldType::Decimal],
            ['photo', 'Фото', FieldType::Image],
            ['description', 'Описание', FieldType::Text],
            ['category', 'Категория', FieldType::Select, false, ['Основное', 'Аксессуары']],
            ['in_stock', 'В наличии', FieldType::Boolean],
        ]);
        $this->collection($tenant, 'orders', 'Заказы', 'Заказы с сайта', [
            ['name', 'Имя', FieldType::String, true],
            ['phone', 'Телефон', FieldType::String, true],
            ['product', 'Товар', FieldType::String],
            ['comment', 'Комментарий', FieldType::Text],
            ['status', 'Статус', FieldType::Select, false, ['Новый', 'Подтверждён', 'Выполнен']],
        ]);

        $repository = new RecordRepository($products);

        foreach ([['Товар первый', 'first', 1990], ['Товар второй', 'second', 3490], ['Аксессуар', 'accessory', 590]] as $i => [$name, $slug, $price]) {
            $repository->create([
                'name' => $name,
                'slug' => $slug,
                'price' => $price,
                'description' => 'Описание товара: материалы, размеры, комплектация.',
                'category' => $i === 2 ? 'Аксессуары' : 'Основное',
                'in_stock' => true,
            ]);
        }

        $this->publish($products, 'catalog', 'slug', 'in_stock', [
            $this->section([
                $this->node('columns', ['columns' => '2', 'gap' => 'large'], [
                    $this->node('record_field', ['field' => 'photo', 'style' => 'image']),
                    $this->node('section', ['padding' => 'none'], [
                        $this->node('record_field', ['field' => 'category', 'style' => 'tags']),
                        $this->node('record_field', ['field' => 'name', 'style' => 'h1']),
                        $this->node('record_field', ['field' => 'price', 'style' => 'h2', 'label' => '₽']),
                        $this->node('record_field', ['field' => 'description', 'style' => 'text']),
                    ]),
                ]),
            ], '', 'large', 'wide'),
            $this->section([$this->orderForm()], '#f9fafb', 'large', 'narrow'),
        ]);

        $list = fn (bool $paginate) => $this->node('records', [
            'collection' => 'products',
            'title' => $paginate ? 'Каталог' : 'Популярные товары',
            'subtitle_field' => 'price',
            'image_field' => 'photo',
            'sort' => 'title',
            'limit' => $paginate ? 12 : 3,
            'paginate' => $paginate,
            'columns' => '3',
        ]);

        $this->home($pages, [
            $this->node('hero', ['title' => $tenant->name, 'subtitle' => 'Чем хорош ваш магазин — в одном предложении.', 'button_label' => 'В каталог', 'button_url' => '/catalog']),
            $this->section([$list(false)]),
            $this->section([$this->node('features', ['title' => 'Доставка и оплата'])], '#f9fafb'),
        ]);
        $this->page($pages, 'Каталог', 'catalog', 'Все товары', [$this->section([$list(true)], '', 'large', 'wide')]);
        $this->page($pages, 'Контакты', 'contacts', 'Как с нами связаться', [
            $this->section([
                $this->node('heading', ['text' => 'Контакты', 'level' => 'h1']),
                $this->node('text', ['text' => "Телефон: +7 (000) 000-00-00\n\nАдрес: город, улица, дом"]),
                $this->orderForm(),
            ], '', 'large', 'narrow'),
        ]);

        $this->menu($tenant, ['home' => 'Главная', 'catalog' => 'Каталог', 'contacts' => 'Контакты']);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderForm(): array
    {
        return $this->node('form', [
            'collection' => 'orders',
            'fields' => ['name', 'phone', 'product', 'comment'],
            'title' => 'Оформить заказ',
            'submit_label' => 'Заказать',
            'success_message' => 'Спасибо! Мы перезвоним, чтобы подтвердить заказ.',
        ]);
    }

    /**
     * Creates a collection; fields are [name, label, type, required?, choices?, unique?, related collection?].
     *
     * @param  list<array<int, mixed>>  $fields
     */
    private function collection(Tenant $tenant, string $name, string $label, string $description, array $fields): Collection
    {
        $collection = $this->schema->createCollection($tenant, ['name' => $name, 'label' => $label, 'description' => $description]);

        foreach ($fields as $field) {
            $this->schema->addField($collection, array_filter([
                'name' => $field[0],
                'label' => $field[1],
                'type' => $field[2],
                'is_required' => $field[3] ?? false,
                'options' => isset($field[4]) ? ['choices' => $field[4]] : null,
                'is_unique' => $field[5] ?? false,
                'related_collection_id' => isset($field[6]) && $field[6] instanceof Collection ? $field[6]->id : null,
            ], fn (mixed $value) => $value !== null));
        }

        $collection->forceFill(['display_field' => $fields[0][0]])->save();

        return $collection->fresh() ?? $collection;
    }

    /**
     * Publishes a collection as a site section with a record page template.
     *
     * @param  list<array<string, mixed>>  $template
     */
    private function publish(Collection $collection, string $path, string $slugField, string $publishedField, array $template): void
    {
        $collection->forceFill([
            'site_path' => $path,
            'site_slug_field' => $slugField,
            'site_published_field' => $publishedField,
            'site_template' => $this->blocks->sanitize($template, 'template', $this->collectionNames($collection->tenant_id)),
        ])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function home(Collection $pages, array $content): void
    {
        $id = (int) DB::table($pages->table_name)->where('slug', PagesCollection::HOME_SLUG)->value('id');

        if ($id) {
            (new RecordRepository($pages))->update($id, ['content' => $content]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function page(Collection $pages, string $title, string $slug, string $description, array $content): void
    {
        (new RecordRepository($pages))->create([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'is_published' => true,
            'content' => $content,
        ]);
    }

    /**
     * @param  array<string, string>  $items  page slug => label
     */
    private function menu(Tenant $tenant, array $items): void
    {
        $menu = [];

        foreach ($items as $slug => $label) {
            $menu[] = ['label' => $label, 'type' => 'page', 'page' => $slug];
        }

        $this->design->for($tenant)->forceFill(['menu' => $this->design->menu($menu)])->save();
    }

    /**
     * @return list<string>
     */
    private function collectionNames(int $tenantId): array
    {
        return array_values(Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->pluck('name')->map(fn (mixed $n) => (string) $n)->all());
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function section(array $children, string $background = '', string $padding = 'large', string $width = 'normal'): array
    {
        return $this->node('section', ['background' => $background, 'padding' => $padding, 'width' => $width], $children);
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<array<string, mixed>>|null  $children
     * @return array<string, mixed>
     */
    private function node(string $type, array $props, ?array $children = null): array
    {
        return array_filter(['type' => $type, 'props' => $props, 'children' => $children], fn (mixed $value) => $value !== null);
    }
}

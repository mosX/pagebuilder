<?php

namespace Database\Seeders;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A small blog schema (authors, categories, articles with relations) in the main company,
 * so the collection builder has something to show. Skipped if it already exists.
 */
class DemoCollectionsSeeder extends Seeder
{
    public function run(SchemaManager $schema, PagesCollection $pages, BlockRegistry $blocks): void
    {
        $tenant = Tenant::where('slug', 'main')->firstOrFail();

        $exists = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'articles')->exists();

        if ($exists) {
            $this->command->info('Demo collections already exist.');
        } else {
            $this->createBlog($schema, $tenant);
        }

        $this->showArticlesOnHome($pages->ensure($tenant));
        $this->publishArticles($tenant, $blocks);
        $this->addContactForm($tenant, $schema, $pages->ensure($tenant));
        $this->addShowcaseBlocks($pages->ensure($tenant));
    }

    private function createBlog(SchemaManager $schema, Tenant $tenant): void
    {

        $authors = $schema->createCollection($tenant, ['name' => 'authors', 'label' => 'Авторы']);
        $schema->addField($authors, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String, 'is_required' => true]);
        $schema->addField($authors, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email, 'is_unique' => true]);

        $categories = $schema->createCollection($tenant, ['name' => 'categories', 'label' => 'Рубрики']);
        $schema->addField($categories, ['name' => 'title', 'label' => 'Название', 'type' => FieldType::String, 'is_required' => true]);

        $articles = $schema->createCollection($tenant, ['name' => 'articles', 'label' => 'Статьи', 'description' => 'Демо-коллекция со связями']);
        $schema->addField($articles, ['name' => 'title', 'label' => 'Заголовок', 'type' => FieldType::String, 'is_required' => true]);
        $schema->addField($articles, ['name' => 'body', 'label' => 'Текст', 'type' => FieldType::Text]);
        $schema->addField($articles, ['name' => 'status', 'label' => 'Статус', 'type' => FieldType::Select, 'options' => ['choices' => ['Черновик', 'Опубликовано']]]);
        $schema->addField($articles, ['name' => 'published_at', 'label' => 'Дата публикации', 'type' => FieldType::Date]);
        $schema->addField($articles, ['name' => 'featured', 'label' => 'На главной', 'type' => FieldType::Boolean]);
        $schema->addField($articles, ['name' => 'author', 'label' => 'Автор', 'type' => FieldType::BelongsTo, 'related_collection_id' => $authors->id]);
        $schema->addField($articles, ['name' => 'categories', 'label' => 'Рубрики', 'type' => FieldType::BelongsToMany, 'related_collection_id' => $categories->id]);

        $authorRepo = new RecordRepository($authors->fresh() ?? $authors);
        $ann = $authorRepo->create(['name' => 'Анна Петрова', 'email' => 'anna@example.com']);
        $max = $authorRepo->create(['name' => 'Максим Коваль', 'email' => 'max@example.com']);

        $categoryRepo = new RecordRepository($categories->fresh() ?? $categories);
        $news = $categoryRepo->create(['title' => 'Новости']);
        $guides = $categoryRepo->create(['title' => 'Гайды']);

        $articleRepo = new RecordRepository($articles->fresh() ?? $articles);
        $articleRepo->create([
            'title' => 'Почему не WordPress',
            'body' => 'Реальные таблицы вместо одной wp_posts.',
            'status' => 'Опубликовано',
            'published_at' => now()->toDateString(),
            'featured' => true,
            'author' => $ann['id'],
            'categories' => [$news['id'], $guides['id']],
        ]);
        $articleRepo->create([
            'title' => 'Как устроены коллекции',
            'status' => 'Черновик',
            'author' => $max['id'],
            'categories' => [$guides['id']],
        ]);

        $this->command->info('Demo collections created: authors, categories, articles.');
    }

    /**
     * Adds a "latest articles" data block to the home page, once.
     */
    /**
     * Publishes articles as the /articles site section with a record page template, once.
     */
    /**
     * A "leads" collection and a contact form on the home page that fills it, once.
     */
    /**
     * Features after the hero and an FAQ before the last section of the home page, once.
     */
    private function addShowcaseBlocks(Collection $pages): void
    {
        $repository = new RecordRepository($pages);
        $id = (int) DB::table($pages->table_name)->where('slug', PagesCollection::HOME_SLUG)->value('id');
        $home = $id ? $repository->find($id) : null;
        $content = is_array($home['content'] ?? null) ? $home['content'] : [];

        if (! $home || collect($content)->contains('type', 'features')) {
            return;
        }

        $section = fn (array $child, string $background = '') => [
            'type' => 'section',
            'props' => ['padding' => 'large', 'width' => 'wide', 'background' => $background],
            'children' => [$child],
        ];

        array_splice($content, 1, 0, [$section(['type' => 'features', 'props' => ['title' => 'Почему мы']])]);
        array_splice($content, count($content) - 1, 0, [$section(['type' => 'faq', 'props' => []], '#f9fafb')]);

        $repository->update($id, ['content' => $content]);
        $this->command->info('Home page shows features and FAQ blocks.');
    }

    private function addContactForm(Tenant $tenant, SchemaManager $schema, Collection $pages): void
    {
        $exists = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'leads')->exists();

        if ($exists) {
            return;
        }

        $leads = $schema->createCollection($tenant, ['name' => 'leads', 'label' => 'Заявки', 'description' => 'Заявки с формы на сайте']);
        $schema->addField($leads, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String, 'is_required' => true]);
        $schema->addField($leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email, 'is_required' => true]);
        $schema->addField($leads, ['name' => 'message', 'label' => 'Сообщение', 'type' => FieldType::Text]);
        $schema->addField($leads, ['name' => 'status', 'label' => 'Статус', 'type' => FieldType::Select, 'options' => ['choices' => ['Новая', 'В работе', 'Закрыта']]]);

        $repository = new RecordRepository($pages);
        $id = (int) DB::table($pages->table_name)->where('slug', PagesCollection::HOME_SLUG)->value('id');
        $home = $id ? $repository->find($id) : null;

        if (! $home) {
            return;
        }

        $content = is_array($home['content'] ?? null) ? $home['content'] : [];
        $content[] = [
            'type' => 'section',
            'props' => ['padding' => 'large'],
            'children' => [[
                'type' => 'form',
                'props' => [
                    'collection' => 'leads',
                    'fields' => ['name', 'email', 'message'],
                    'title' => 'Связаться с нами',
                    'submit_label' => 'Отправить',
                    'success_message' => 'Спасибо! Мы ответим в течение дня.',
                ],
            ]],
        ];

        $repository->update($id, ['content' => $content]);
        $this->command->info('Home page has a contact form that fills "Заявки".');
    }

    private function publishArticles(Tenant $tenant, BlockRegistry $blocks): void
    {
        $articles = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'articles')->first();

        if (! $articles || $articles->site_path) {
            return;
        }

        $template = $blocks->sanitize([
            ['type' => 'section', 'props' => ['width' => 'narrow', 'padding' => 'large'], 'children' => [
                ['type' => 'record_field', 'props' => ['field' => 'categories', 'style' => 'tags']],
                ['type' => 'record_field', 'props' => ['field' => 'title', 'style' => 'h1']],
                ['type' => 'record_field', 'props' => ['field' => 'author', 'style' => 'meta', 'label' => 'Автор:']],
                ['type' => 'record_field', 'props' => ['field' => 'published_at', 'style' => 'meta', 'label' => 'Опубликовано:']],
                ['type' => 'record_field', 'props' => ['field' => 'body', 'style' => 'text']],
                ['type' => 'button', 'props' => ['label' => '← Все статьи', 'url' => '/', 'variant' => 'link']],
            ]],
        ], 'template');

        $articles->forceFill([
            'site_path' => 'articles',
            'site_slug_field' => 'id',
            'site_template' => $template,
        ])->save();

        $this->command->info('Articles are published at /articles/{id}.');
    }

    private function showArticlesOnHome(Collection $pages): void
    {
        $repository = new RecordRepository($pages);
        $id = (int) DB::table($pages->table_name)->where('slug', PagesCollection::HOME_SLUG)->value('id');
        $home = $id ? $repository->find($id) : null;
        $content = is_array($home['content'] ?? null) ? $home['content'] : [];

        if (! $home || collect($content)->contains('type', 'records')) {
            return;
        }

        $content[] = [
            'type' => 'section',
            'props' => ['background' => '#f9fafb', 'padding' => 'large'],
            'children' => [[
                'type' => 'records',
                'props' => ['collection' => 'articles', 'title' => 'Последние статьи', 'subtitle_field' => 'author', 'limit' => 6, 'columns' => '3'],
            ]],
        ];

        $repository->update($id, ['content' => $content]);
        $this->command->info('Home page shows the latest articles.');
    }
}

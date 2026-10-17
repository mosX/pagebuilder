<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Tenant;
use App\Support\Blocks\BlockDataResolver;
use App\Support\Blocks\RenderContext;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data of a company's public site: navigation, published pages and record pages
 * (collections published as site sections), with data blocks resolved.
 * Shared by the server-rendered site, the SSR payload and the public JSON API.
 */
class SitePresenter
{
    public function __construct(
        private PagesCollection $pages,
        private BlockDataResolver $resolver,
        private SiteLanguages $languages,
    ) {}

    /**
     * @param  string  $baseUrl  '' on the company subdomain, '/site/{slug}' for the preview path
     *                           (plus '/{locale}' for an additional language).
     * @return array{name: string, slug: string, base_url: string, pages: array<int, array{title: string, slug: string, url: string}>}|null
     */
    public function site(Tenant $tenant, string $baseUrl = '', ?string $locale = null): ?array
    {
        $collection = $this->pages->find($tenant);

        if (! $collection || ! $tenant->is_active) {
            return null;
        }

        $rows = DB::table($collection->table_name)
            ->where('is_published', true)
            ->when($collection->field(Scheduling::PAGES_FIELD), fn ($q) => Scheduling::released($q, Scheduling::PAGES_FIELD, $tenant->id))
            ->when($collection->field('members_only'), fn ($q) => $q->where(fn ($q) => $q->where('members_only', false)->orWhereNull('members_only')))
            ->orderBy('id')
            ->get(['id', 'title', 'slug'])
            ->map(fn (object $row) => (array) $row)
            ->all();

        $pages = array_map(fn (array $row) => [
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'url' => $this->url($baseUrl, (string) ($row['slug'] ?? '')),
        ], $this->languages->overlayMany($collection, $rows, $locale));

        return ['name' => $tenant->name, 'slug' => $tenant->slug, 'base_url' => $baseUrl, 'pages' => $pages];
    }

    /**
     * @return array{title: string, slug: string, description: string|null, members_only: bool, blocks: array<int, array<string, mixed>>}|null
     */
    public function page(Tenant $tenant, string $slug, string $baseUrl = '', int $listPage = 1, ?string $locale = null): ?array
    {
        $collection = $this->pages->find($tenant);

        $page = $collection
            ? (new RecordRepository($collection))->firstWhere([['slug', $slug], ['is_published', true]], Scheduling::PAGES_FIELD)
            : null;

        if (! $collection || ! $page) {
            return null;
        }

        $page = $this->languages->overlay($collection, $page, $locale);
        $context = new RenderContext($tenant, $baseUrl, $collection, $page, $listPage, locale: $locale);

        return [
            'title' => is_string($page['title'] ?? null) ? $page['title'] : '',
            'slug' => $slug,
            'description' => is_string($page['description'] ?? null) ? $page['description'] : null,
            // Shown to signed-in site visitors only.
            'members_only' => (bool) ($page['members_only'] ?? false),
            'blocks' => $this->resolver->resolveTree($context, is_array($page['content'] ?? null) ? $page['content'] : []),
        ];
    }

    /**
     * A record of a collection published at /{path}/{slug}, rendered with the collection's template.
     *
     * @return array{title: string, slug: string, description: string|null, members_only: bool, blocks: array<int, array<string, mixed>>}|null
     */
    public function recordPage(Tenant $tenant, string $path, string $slug, string $baseUrl = '', int $listPage = 1, ?string $locale = null): ?array
    {
        $collection = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('site_path', $path)
            ->first();

        if (! $collection) {
            return null;
        }

        $repository = new RecordRepository($collection);
        $record = $repository->firstWhere(array_filter([
            [$collection->site_slug_field ?: 'id', $slug],
            $collection->site_published_field ? [$collection->site_published_field, true] : null,
        ]), $collection->site_publish_at_field);

        if (! $record) {
            return null;
        }

        $record = $this->languages->overlay($collection, $record, $locale);
        $display = $repository->displayField();
        $context = new RenderContext($tenant, $baseUrl, $collection, $record, $listPage, locale: $locale);

        return [
            'title' => $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : "#{$record['id']}",
            'slug' => "{$path}/{$slug}",
            'description' => $this->description($collection, $record),
            'members_only' => false,
            'blocks' => $this->resolver->resolveTree($context, $collection->site_template ?? []),
        ];
    }

    public function url(string $baseUrl, string $slug): string
    {
        return $slug === PagesCollection::HOME_SLUG ? ($baseUrl ?: '/') : "{$baseUrl}/{$slug}";
    }

    /**
     * Meta description of a record page: the first non-empty text field.
     *
     * @param  array<string, mixed>  $record
     */
    private function description(Collection $collection, array $record): ?string
    {
        $field = $collection->fields->first(
            fn (CollectionField $f) => $f->type === FieldType::Text && is_string($record[$f->name] ?? null) && $record[$f->name] !== '',
        );

        return $field ? Str::limit((string) $record[$field->name], 300) : null;
    }
}

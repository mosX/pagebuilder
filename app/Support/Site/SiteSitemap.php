<?php

namespace App\Support\Site;

use App\Models\Collection;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * sitemap.xml of a company site: published pages and record pages that guests can see
 * (released, not for members only), in every language with hreflang alternates.
 * Cached until the company changes something (the page cache version).
 */
class SiteSitemap
{
    /** Search engines accept up to 50 000 URLs per sitemap. */
    private const MAX_URLS = 45000;

    public function __construct(
        private PagesCollection $pages,
        private SiteDesign $design,
        private SiteLanguages $languages,
        private RecordUrls $urls,
        private SiteCache $cache,
    ) {}

    public function xml(Tenant $tenant): string
    {
        return Cache::remember(
            "sitemap:{$tenant->id}:{$this->cache->version($tenant->id)}",
            now()->addHours(6),
            fn () => $this->build($tenant),
        );
    }

    private function build(Tenant $tenant): string
    {
        $languages = $this->languages->of($this->design->for($tenant));
        $codes = [$languages['default'], ...$languages['extra']];
        $root = $tenant->siteUrl();
        $entries = [...$this->pageEntries($tenant), ...$this->recordEntries($tenant)];

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];

        foreach (array_slice($entries, 0, intdiv(self::MAX_URLS, count($codes))) as [$path, $updated]) {
            $alternates = [];

            foreach ($codes as $code) {
                $alternates[$code] = $this->url($root, $code === $languages['default'] ? '' : $code, $path);
            }

            foreach ($alternates as $url) {
                $xml[] = '  <url>';
                $xml[] = '    <loc>'.e($url).'</loc>';

                if ($updated) {
                    $xml[] = '    <lastmod>'.$updated.'</lastmod>';
                }

                if (count($codes) > 1) {
                    foreach ($alternates as $code => $alternate) {
                        $xml[] = '    <xhtml:link rel="alternate" hreflang="'.e($code).'" href="'.e($alternate).'"/>';
                    }
                }

                $xml[] = '  </url>';
            }
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml)."\n";
    }

    /**
     * @return list<array{0: string, 1: string|null}> path and last change date
     */
    private function pageEntries(Tenant $tenant): array
    {
        $collection = $this->pages->find($tenant);

        if (! $collection) {
            return [];
        }

        return array_values(DB::table($collection->table_name)
            ->where('is_published', true)
            ->when($collection->field('members_only'), fn ($q) => $q->where(fn ($q) => $q->where('members_only', false)->orWhereNull('members_only')))
            ->when($collection->field(Scheduling::PAGES_FIELD), fn ($q) => Scheduling::released($q, Scheduling::PAGES_FIELD, $tenant->id))
            ->orderBy('id')
            ->limit(self::MAX_URLS)
            ->get(['slug', 'updated_at'])
            ->map(fn (object $row) => [
                $row->slug === PagesCollection::HOME_SLUG ? '' : (string) $row->slug,
                $this->date($row->updated_at),
            ])
            ->all());
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    private function recordEntries(Tenant $tenant): array
    {
        $entries = [];

        $collections = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('site_path')
            ->with('fields')
            ->get();

        foreach ($collections as $collection) {
            $slugField = $collection->site_slug_field && $collection->site_slug_field !== 'id' ? $collection->field($collection->site_slug_field) : null;
            $published = $collection->site_published_field ? $collection->field($collection->site_published_field)?->column() : null;
            $releasedBy = $collection->site_publish_at_field ? $collection->field($collection->site_publish_at_field)?->column() : null;

            $rows = DB::table($collection->table_name)
                ->when($published, fn ($q) => $q->where((string) $published, true))
                ->when($releasedBy, fn ($q) => Scheduling::released($q, (string) $releasedBy, $tenant->id))
                ->orderBy('id')
                ->limit(self::MAX_URLS)
                ->get(array_filter(['id', 'updated_at', $slugField?->column()]));

            foreach ($rows as $row) {
                $record = (array) $row;
                $url = $this->urls->record($collection, $slugField ? [...$record, $slugField->name => $record[(string) $slugField->column()] ?? null] : $record);

                if ($url) {
                    $entries[] = [ltrim($url, '/'), $this->date($row->updated_at ?? null)];
                }
            }
        }

        return $entries;
    }

    private function url(string $root, string $locale, string $path): string
    {
        $base = $locale === '' ? $root : "{$root}/{$locale}";

        return $path === '' ? "{$base}/" : "{$base}/{$path}";
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->toDateString() : null;
    }
}

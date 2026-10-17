<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Translation;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\RichText;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Full-text search over a company site: published pages (title, description and the
 * text of their blocks) and records of collections published as site sections.
 *
 * Pages are matched in PHP over the text of their blocks, so links, colors and other
 * props never match; record sections are matched in SQL over their text fields.
 */
class SiteSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_RESULTS = 30;

    /** Pages read per search; a company site is far below this. */
    private const MAX_PAGES = 500;

    private const PER_COLLECTION = 15;

    public function __construct(
        private PagesCollection $pages,
        private BlockRegistry $blocks,
        private SiteLanguages $languages,
        private RecordUrls $urls,
    ) {}

    /**
     * @return list<array{title: string, url: string, excerpt: string}>
     */
    public function search(CurrentSite $site, string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [];
        }

        $query = mb_substr($query, 0, 100);

        return array_slice([...$this->searchPages($site, $query), ...$this->searchRecords($site, $query)], 0, self::MAX_RESULTS);
    }

    /**
     * @return list<array{title: string, url: string, excerpt: string}>
     */
    private function searchPages(CurrentSite $site, string $query): array
    {
        $collection = $this->pages->find($site->tenant);

        if (! $collection) {
            return [];
        }

        $hasMembersOnly = $collection->field('members_only') !== null;

        $rows = DB::table($collection->table_name)
            ->where('is_published', true)
            ->when($collection->field(Scheduling::PAGES_FIELD), fn (Builder $q) => Scheduling::released($q, Scheduling::PAGES_FIELD, $site->tenant->id))
            // Pages for signed-in visitors are not revealed to guests, even by title.
            ->when($hasMembersOnly && ! $site->member, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('members_only', false)->orWhereNull('members_only')))
            ->orderBy('id')
            ->limit(self::MAX_PAGES)
            ->get(['id', 'title', 'slug', 'description', 'content'])
            ->map(fn (object $row) => [...(array) $row, 'content' => is_string($row->content) ? json_decode($row->content, true) : null])
            ->all();

        $results = [];

        foreach ($this->languages->overlayMany($collection, $rows, $site->locale()) as $page) {
            $title = is_string($page['title'] ?? null) ? $page['title'] : '';
            $description = is_string($page['description'] ?? null) ? $page['description'] : '';
            $body = $this->treeText(is_array($page['content'] ?? null) ? $page['content'] : []);

            if (! $this->contains("{$title} {$description} {$body}", $query)) {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $site->url($page['slug'] === PagesCollection::HOME_SLUG ? '' : (string) $page['slug']),
                'excerpt' => $this->excerpt($this->contains($description, $query) || ! $this->contains($body, $query) ? $description : $body, $query),
            ];
        }

        return $results;
    }

    /**
     * @return list<array{title: string, url: string, excerpt: string}>
     */
    private function searchRecords(CurrentSite $site, string $query): array
    {
        $collections = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $site->tenant->id)
            ->whereNotNull('site_path')
            ->with('fields')
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($collections as $collection) {
            $fields = $collection->fields
                ->filter(fn (CollectionField $f) => in_array($f->type, [FieldType::String, FieldType::Text], true))
                ->values();

            if ($fields->isEmpty()) {
                continue;
            }

            $ids = $this->matchingRecordIds($site, $collection, array_values($fields->map(fn (CollectionField $f) => (string) $f->column())->all()), $query);

            if ($ids === []) {
                continue;
            }

            $repository = new RecordRepository($collection);
            $display = $repository->displayField();
            $text = $fields->firstWhere('type', FieldType::Text);
            $records = array_values(array_filter(array_map(fn (int $id) => $repository->find($id), $ids)));

            foreach ($this->languages->overlayMany($collection, $records, $site->locale()) as $record) {
                $url = $this->urls->record($collection, $record, $site->baseUrl);
                $title = $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : "#{$record['id']}";

                if ($url) {
                    $results[] = [
                        'title' => $title,
                        'url' => $url,
                        'excerpt' => $this->excerpt($text && is_string($record[$text->name] ?? null) ? $record[$text->name] : '', $query),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Ids of published records whose text fields (or their translation) contain the query.
     *
     * @param  list<string>  $columns
     * @return list<int>
     */
    private function matchingRecordIds(CurrentSite $site, Collection $collection, array $columns, string $query): array
    {
        $like = '%'.addcslashes($query, '\\%_').'%';
        $published = $collection->site_published_field;

        $releasedBy = $collection->site_publish_at_field ? $collection->field($collection->site_publish_at_field)?->column() : null;

        $ids = DB::table($collection->table_name)
            ->when($published, fn (Builder $q) => $q->where((string) $published, true))
            ->when($releasedBy, fn (Builder $q) => Scheduling::released($q, (string) $releasedBy, $site->tenant->id))
            ->where(function (Builder $q) use ($columns, $like) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            })
            ->orderByDesc('id')
            ->limit(self::PER_COLLECTION)
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        if (! $site->locale()) {
            return array_values($ids);
        }

        // Matches in the current language's translations count too.
        $translated = Translation::withoutGlobalScope('tenant')
            ->where('collection_id', $collection->id)
            ->where('locale', $site->locale())
            ->limit(self::MAX_PAGES)
            ->get(['record_id', 'data'])
            ->filter(fn (Translation $t) => $this->contains(implode(' ', array_filter($t->data, 'is_string')), $query))
            ->pluck('record_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        $extra = $translated === [] ? [] : DB::table($collection->table_name)
            ->whereIn('id', $translated)
            ->when($published, fn (Builder $q) => $q->where((string) $published, true))
            ->when($releasedBy, fn (Builder $q) => Scheduling::released($q, (string) $releasedBy, $site->tenant->id))
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        return array_values(array_slice(array_unique([...$ids, ...$extra]), 0, self::PER_COLLECTION));
    }

    /**
     * Visible text of a block tree: text, rich text and textarea props, including list items.
     *
     * @param  array<int, mixed>  $nodes
     */
    public function treeText(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $schema = (array) ($this->blocks->get(is_string($node['type'] ?? null) ? $node['type'] : '')['props'] ?? []);
            $parts[] = $this->propsText($schema, (array) ($node['props'] ?? []));
            $parts[] = $this->treeText(is_array($node['children'] ?? null) ? $node['children'] : []);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $props
     */
    private function propsText(array $schema, array $props): string
    {
        $parts = [];

        foreach ($schema as $key => $prop) {
            $type = is_array($prop) ? ($prop['type'] ?? null) : null;
            $value = $props[$key] ?? null;

            if (in_array($type, ['text', 'textarea'], true) && is_string($value)) {
                $parts[] = $value;
            } elseif ($type === 'richtext') {
                $parts[] = RichText::plainText($value);
            } elseif ($type === 'list' && is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = $this->propsText((array) ($prop['item'] ?? []), is_array($item) ? $item : []);
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    private function contains(string $text, string $query): bool
    {
        return mb_stripos($text, $query) !== false;
    }

    /**
     * About 200 characters of the text around the first match.
     */
    private function excerpt(string $text, string $query): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        $position = mb_stripos($text, $query);

        if ($position === false || $position < 80) {
            return Str::limit($text, 200);
        }

        return '…'.Str::limit(mb_substr($text, $position - 60), 200);
    }
}

<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\BlockPattern;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\CustomTemplate;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\SchemaTransfer;
use App\Support\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Saves a company site as a template and creates new companies from it.
 *
 * The snapshot holds the collection schema (with section templates), pages, header,
 * footer, 404 page, theme, menu, languages, saved sections, translations, and the
 * records of chosen collections with their ids, so relations stay intact. Media files
 * are copied into the template's own storage, so it outlives the source company.
 * Paths of the source company's files are stored with a placeholder and rewritten
 * for the new company; ids of saved sections are re-mapped in every block tree.
 */
class SiteSnapshots
{
    public const VERSION = 1;

    public const MAX_RECORDS = 500;

    public const MAX_MEDIA_BYTES = 300 * 1024 * 1024;

    private const TENANT_MARKER = '{{tenant}}';

    private const SETTINGS = ['theme', 'menu', 'header', 'footer', 'not_found', 'logo', 'favicon', 'languages', 'account'];

    public function __construct(
        private SchemaTransfer $schema,
        private PagesCollection $pages,
        private SiteDesign $design,
        private MediaLibrary $media,
        private SiteCache $cache,
    ) {}

    /**
     * @param  list<string>  $recordCollections  Collections whose records go into the template.
     */
    public function capture(Tenant $tenant, User $user, string $name, ?string $description, array $recordCollections): CustomTemplate
    {
        $template = new CustomTemplate;
        $template->forceFill([
            'user_id' => $user->id,
            'source_tenant_id' => $tenant->id,
            'name' => $name,
            'description' => $description,
            'data' => [],
        ])->save();

        $pages = $this->pages->find($tenant);
        $settings = $this->design->for($tenant);

        $data = [
            'version' => self::VERSION,
            'schema' => $this->schema->export($tenant),
            'pages' => $pages ? $this->rows($pages->table_name) : [],
            'records' => $this->records($tenant, $recordCollections),
            'settings' => array_intersect_key($settings->getAttributes(), array_flip(self::SETTINGS)),
            'patterns' => BlockPattern::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderBy('id')
                ->get()->map(fn (BlockPattern $p) => ['id' => $p->id, 'name' => $p->name, 'tree' => $p->tree])->all(),
            'translations' => $this->translations($tenant, [...($pages ? [$pages->name] : []), ...$recordCollections]),
            'media' => $this->copyMediaToTemplate($tenant, $template),
        ];

        // The source company's file paths become a placeholder, filled in for each new company.
        $template->forceFill(['data' => $this->replaceStrings($data, [
            "tenants/{$tenant->id}/" => 'tenants/'.self::TENANT_MARKER.'/',
            "tenants\\/{$tenant->id}\\/" => 'tenants\\/'.self::TENANT_MARKER.'\\/',
        ])])->save();

        return $template;
    }

    /**
     * Fills a freshly created company from a template.
     */
    public function apply(Tenant $tenant, CustomTemplate $template): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->replaceStrings($template->data, [self::TENANT_MARKER => (string) $tenant->id]);
        $pages = $this->pages->ensure($tenant);

        $patterns = $this->restorePatterns($tenant, (array) ($data['patterns'] ?? []));
        $data = $this->remapPatterns($data, $patterns);

        $this->schema->import($tenant, (array) ($data['schema'] ?? []));

        Schema::disableForeignKeyConstraints();

        try {
            DB::table($pages->table_name)->delete();
            $this->insertRows($pages->table_name, (array) ($data['pages'] ?? []));
            $this->restoreRecords($tenant, (array) ($data['records'] ?? []));
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->restoreTranslations($tenant, (array) ($data['translations'] ?? []));
        $this->restoreSettings($tenant, (array) ($data['settings'] ?? []));
        $this->restoreMedia($tenant, $template, (array) ($data['media'] ?? []));
        $this->cache->flush($tenant->id);
    }

    public function delete(CustomTemplate $template): void
    {
        Storage::disk($this->media->disk())->deleteDirectory($template->storageDirectory());
        $template->delete();
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{rows: list<array<string, mixed>>, pivots: array<string, list<array<string, mixed>>>}>
     */
    private function records(Tenant $tenant, array $names): array
    {
        $records = [];

        $collections = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereNull('kind')
            ->whereIn('name', $names)
            ->with('fields')
            ->get();

        foreach ($collections as $collection) {
            $rows = $this->rows($collection->table_name, self::MAX_RECORDS);
            $ids = array_column($rows, 'id');
            $pivots = [];

            foreach ($collection->fields->filter(fn (CollectionField $f) => $f->type->usesPivot()) as $field) {
                $pivots[$field->name] = array_values(array_map(
                    fn (object $row) => (array) $row,
                    DB::table($field->pivotTable())->whereIn('source_id', $ids)->get(['source_id', 'target_id'])->all(),
                ));
            }

            $records[$collection->name] = ['rows' => $rows, 'pivots' => $pivots];
        }

        return $records;
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function translations(Tenant $tenant, array $names): array
    {
        $collections = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereIn('name', $names)->pluck('name', 'id');

        return array_values(Translation::withoutGlobalScope('tenant')
            ->whereIn('collection_id', $collections->keys())
            ->get()
            ->map(fn (Translation $t) => [
                'collection' => $collections[$t->collection_id],
                'record_id' => $t->record_id,
                'locale' => $t->locale,
                'data' => $t->data,
            ])
            ->all());
    }

    /**
     * Copies the company's media files into the template's storage (up to a size limit).
     *
     * @return list<array<string, mixed>>
     */
    private function copyMediaToTemplate(Tenant $tenant, CustomTemplate $template): array
    {
        $disk = Storage::disk($this->media->disk());
        $prefix = "tenants/{$tenant->id}/";
        $total = 0;
        $copied = [];

        foreach (Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderBy('id')->get() as $media) {
            if (! str_starts_with($media->path, $prefix) || ! $disk->exists($media->path) || $total + $media->size > self::MAX_MEDIA_BYTES) {
                continue;
            }

            $relative = substr($media->path, strlen($prefix));
            $disk->copy($media->path, $template->storageDirectory().'/'.$relative);
            $total += $media->size;

            $copied[] = [
                'path' => $relative,
                'original_name' => $media->original_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'width' => $media->width,
                'height' => $media->height,
                'alt' => $media->alt,
            ];
        }

        return $copied;
    }

    /**
     * @param  array<int|string, mixed>  $patterns  As stored in the template.
     * @return array<int, int> old id => new id
     */
    private function restorePatterns(Tenant $tenant, array $patterns): array
    {
        $map = [];

        foreach ($patterns as $pattern) {
            $model = new BlockPattern;
            $pattern = is_array($pattern) ? $pattern : [];
            $model->forceFill(['tenant_id' => $tenant->id, 'name' => (string) ($pattern['name'] ?? ''), 'tree' => []])->save();
            $map[(int) ($pattern['id'] ?? 0)] = $model->id;
        }

        // Sections may contain sections: trees are written once every new id is known.
        foreach (array_filter($patterns, 'is_array') as $pattern) {
            BlockPattern::withoutGlobalScope('tenant')->whereKey($map[(int) ($pattern['id'] ?? 0)])
                ->first()?->forceFill(['tree' => $this->remapPatterns((array) ($pattern['tree'] ?? []), $map)])->save();
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function restoreRecords(Tenant $tenant, array $records): void
    {
        foreach ($records as $name => $set) {
            $collection = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', $name)->with('fields')->first();

            if (! $collection || ! is_array($set)) {
                continue;
            }

            $this->insertRows($collection->table_name, (array) ($set['rows'] ?? []));

            foreach ((array) ($set['pivots'] ?? []) as $fieldName => $rows) {
                $field = $collection->field((string) $fieldName);

                if ($field && $field->type === FieldType::BelongsToMany && is_array($rows)) {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($field->pivotTable())->insert($chunk);
                    }
                }
            }
        }
    }

    /**
     * Inserts rows with their ids, keeping only columns the table has.
     *
     * @param  array<int, mixed>  $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $clean = array_values(array_filter(array_map(
            fn (mixed $row) => is_array($row) ? array_intersect_key($row, $columns) : null,
            $rows,
        )));

        foreach (array_chunk($clean, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @param  array<int, mixed>  $translations
     */
    private function restoreTranslations(Tenant $tenant, array $translations): void
    {
        $collections = Collection::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->pluck('id', 'name');

        foreach ($translations as $item) {
            $collectionId = is_array($item) ? ($collections[(string) ($item['collection'] ?? '')] ?? null) : null;

            if ($collectionId) {
                (new Translation)->forceFill([
                    'tenant_id' => $tenant->id,
                    'collection_id' => $collectionId,
                    'record_id' => (int) $item['record_id'],
                    'locale' => (string) $item['locale'],
                    'data' => (array) $item['data'],
                ])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function restoreSettings(Tenant $tenant, array $settings): void
    {
        $model = $this->design->for($tenant);

        foreach (array_intersect_key($settings, array_flip(self::SETTINGS)) as $key => $value) {
            // Stored as the raw column values (JSON text for structured settings).
            $model->setRawAttributes([...$model->getAttributes(), $key => $value]);
        }

        $model->save();
    }

    /**
     * @param  array<int, mixed>  $media
     */
    private function restoreMedia(Tenant $tenant, CustomTemplate $template, array $media): void
    {
        $disk = Storage::disk($this->media->disk());

        foreach ($media as $item) {
            $relative = is_array($item) ? (string) ($item['path'] ?? '') : '';
            $source = $template->storageDirectory().'/'.$relative;

            if ($relative === '' || str_contains($relative, '..') || ! $disk->exists($source)) {
                continue;
            }

            $path = "tenants/{$tenant->id}/{$relative}";
            $disk->copy($source, $path);

            (new Media)->forceFill([
                'tenant_id' => $tenant->id,
                'disk' => $this->media->disk(),
                'path' => $path,
                'original_name' => (string) ($item['original_name'] ?? basename($relative)),
                'mime_type' => (string) ($item['mime_type'] ?? 'application/octet-stream'),
                'size' => (int) ($item['size'] ?? 0),
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
                'alt' => $item['alt'] ?? null,
            ])->save();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, ?int $limit = null): array
    {
        return array_values(DB::table($table)->orderBy('id')->when($limit, fn ($q) => $q->limit((int) $limit))->get()
            ->map(fn (object $row) => (array) $row)
            ->all());
    }

    /**
     * Points "pattern" blocks at the new ids, in trees and in JSON text of block columns.
     *
     * @param  array<int, int>  $map
     */
    private function remapPatterns(mixed $value, array $map): mixed
    {
        if (is_string($value) && str_contains($value, '"pattern"')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? json_encode($this->remapPatterns($decoded, $map)) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (($value['type'] ?? null) === 'pattern' && is_array($value['props'] ?? null) && is_numeric($value['props']['pattern'] ?? null)) {
            $value['props']['pattern'] = $map[(int) $value['props']['pattern']] ?? null;
        }

        return array_map(fn (mixed $item) => $this->remapPatterns($item, $map), $value);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceStrings(mixed $value, array $replacements): mixed
    {
        return match (true) {
            is_string($value) => strtr($value, $replacements),
            is_array($value) => array_map(fn (mixed $item) => $this->replaceStrings($item, $replacements), $value),
            default => $value,
        };
    }
}

<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\SiteSettings;
use App\Models\Translation;
use App\Support\Blocks\BlockRegistry;
use Illuminate\Support\Str;

/**
 * Languages of a company site and translations of records.
 *
 * The default language lives in the record itself; other languages are stored per
 * record in `translations` and overlay the text and block fields. URLs (slugs) are
 * not translated, so links match across languages. Missing translations fall back
 * to the default language.
 */
class SiteLanguages
{
    public const LOCALES = [
        'ru' => 'Русский',
        'uk' => 'Українська',
        'en' => 'English',
        'de' => 'Deutsch',
        'pl' => 'Polski',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
        'kk' => 'Қазақша',
    ];

    public function __construct(private BlockRegistry $blocks) {}

    /**
     * @return array{default: string, extra: list<string>}
     */
    public function of(SiteSettings $settings): array
    {
        return $this->normalize($settings->languages);
    }

    /**
     * @return array{default: string, extra: list<string>}
     */
    public function normalize(mixed $languages): array
    {
        $languages = is_array($languages) ? $languages : [];
        $default = array_key_exists((string) ($languages['default'] ?? ''), self::LOCALES) ? (string) $languages['default'] : 'ru';
        $extra = array_values(array_unique(array_filter(
            (array) ($languages['extra'] ?? []),
            fn ($locale) => is_string($locale) && array_key_exists($locale, self::LOCALES) && $locale !== $default,
        )));

        return ['default' => $default, 'extra' => $extra];
    }

    /**
     * Fields whose values differ per language: texts and block trees, but never the
     * field the record's URL is built from.
     *
     * @return \Illuminate\Support\Collection<int, CollectionField>
     */
    public function translatableFields(Collection $collection): \Illuminate\Support\Collection
    {
        $urlField = $collection->kind === 'pages' ? 'slug' : $collection->site_slug_field;

        return $collection->fields
            ->filter(fn (CollectionField $f) => in_array($f->type, [FieldType::String, FieldType::Text, FieldType::Blocks], true)
                && $f->name !== $urlField)
            ->values();
    }

    /**
     * The record with its translated fields for the locale (the record itself for the default language).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function overlay(Collection $collection, array $record, ?string $locale): array
    {
        return $this->overlayMany($collection, [$record], $locale)[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    public function overlayMany(Collection $collection, array $records, ?string $locale): array
    {
        if (! $locale || $records === []) {
            return array_values($records);
        }

        $translations = Translation::withoutGlobalScope('tenant')
            ->where('collection_id', $collection->id)
            ->where('locale', $locale)
            ->whereIn('record_id', array_column($records, 'id'))
            ->pluck('data', 'record_id');

        $fields = $this->translatableFields($collection)->pluck('name')->all();

        return array_values(array_map(function (array $record) use ($translations, $fields) {
            $data = $translations[$record['id']] ?? [];

            foreach ($fields as $field) {
                $value = is_array($data) ? ($data[$field] ?? null) : null;

                if ($value !== null && $value !== '' && $value !== []) {
                    $record[$field] = $value;
                }
            }

            return $record;
        }, $records));
    }

    /**
     * @return array<string, array<string, mixed>> locale => translated values
     */
    public function forRecord(Collection $collection, int $recordId): array
    {
        return Translation::withoutGlobalScope('tenant')
            ->where('collection_id', $collection->id)
            ->where('record_id', $recordId)
            ->pluck('data', 'locale')
            ->map(fn ($data) => is_array($data) ? $data : [])
            ->all();
    }

    /**
     * Stores translated values (only translatable fields; blocks are sanitized like pages).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> The stored values.
     */
    public function save(Collection $collection, int $recordId, string $locale, array $values, bool $merge = true): array
    {
        $translation = Translation::withoutGlobalScope('tenant')
            ->where(['collection_id' => $collection->id, 'record_id' => $recordId, 'locale' => $locale])
            ->first()
            ?? (new Translation)->forceFill(['collection_id' => $collection->id, 'record_id' => $recordId, 'locale' => $locale]);

        $data = $merge && $translation->exists ? $translation->data : [];
        $collections = Collection::withoutGlobalScope('tenant')->where('tenant_id', $collection->tenant_id)->pluck('name')->map(fn ($n) => (string) $n)->all();

        foreach ($this->translatableFields($collection) as $field) {
            if (! array_key_exists($field->name, $values)) {
                continue;
            }

            $value = $values[$field->name];

            $data[$field->name] = match ($field->type) {
                FieldType::Blocks => $this->blocks->sanitize($value, $field->name, $collections),
                FieldType::Text => is_scalar($value) ? Str::limit((string) $value, 1000000, '') : null,
                default => is_scalar($value) ? Str::limit((string) $value, 255, '') : null,
            };
        }

        $translation->forceFill(['tenant_id' => $collection->tenant_id, 'data' => $data])->save();

        return $data;
    }
}

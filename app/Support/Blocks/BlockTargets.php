<?php

namespace App\Support\Blocks;

use App\Enums\FieldType;
use App\Models\BlockPattern;
use App\Models\Collection;
use App\Models\SiteSettings;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteDesign;
use App\Support\Site\SiteLanguages;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Resolves block constructor targets inside the current company and reads/writes
 * their published block trees. Lookups go through tenant-scoped models, so a key
 * can never reach another company's data.
 */
class BlockTargets
{
    public function __construct(
        private BlockRegistry $registry,
        private SiteDesign $design,
        private TenantContext $context,
        private SiteLanguages $languages,
    ) {}

    private function settings(): SiteSettings
    {
        return $this->design->for($this->context->tenant());
    }

    public function resolve(string $key): BlockTarget
    {
        [$path, $locale] = array_pad(explode('@', $key, 2), 2, null);
        $parts = explode(':', (string) $path);

        if ($locale !== null && ($parts[0] !== 'field' || ! array_key_exists($locale, SiteLanguages::LOCALES))) {
            throw ValidationException::withMessages(['target' => 'Неизвестный язык перевода.']);
        }

        return match ($parts[0]) {
            'field' => $this->field($key, $parts, $locale),
            'template' => new BlockTarget($key, 'template', $this->collection($parts[1] ?? '')),
            'pattern' => new BlockTarget($key, 'pattern', pattern: BlockPattern::query()->findOrFail((int) ($parts[1] ?? 0))),
            'layout' => in_array($parts[1] ?? '', ['header', 'footer'], true)
                ? new BlockTarget($key, 'layout', field: $parts[1])
                : throw ValidationException::withMessages(['target' => 'Неизвестная часть сайта.']),
            default => throw ValidationException::withMessages(['target' => 'Неизвестный объект редактирования.']),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(BlockTarget $target): array
    {
        $tree = match ($target->type) {
            // A translation not started yet begins as a copy of the original.
            'field' => ($target->locale ? ($this->languages->forRecord($this->collectionOf($target), (int) $target->recordId)[$target->locale][(string) $target->field] ?? null) : null)
                ?? (new RecordRepository($this->collectionOf($target)))->find((int) $target->recordId)[(string) $target->field] ?? [],
            'template' => $this->collectionOf($target)->site_template ?? [],
            'pattern' => $target->pattern->tree ?? [],
            'layout' => $this->settings()->{(string) $target->field} ?? [],
            default => [],
        };

        return is_array($tree) ? array_values($tree) : [];
    }

    /**
     * Publishes a tree and returns it as stored (sanitized).
     *
     * @return array<int, array<string, mixed>>
     */
    public function write(BlockTarget $target, mixed $tree): array
    {
        if ($target->type === 'field' && $target->locale) {
            $stored = $this->languages->save($this->collectionOf($target), (int) $target->recordId, $target->locale, [(string) $target->field => $tree]);
            $tree = $stored[(string) $target->field] ?? [];

            return is_array($tree) ? array_values($tree) : [];
        }

        if ($target->type === 'field') {
            $record = (new RecordRepository($this->collectionOf($target)))->update((int) $target->recordId, [(string) $target->field => $tree]);
            $stored = $record[(string) $target->field] ?? [];

            return is_array($stored) ? array_values($stored) : [];
        }

        $clean = $this->sanitize($tree);

        if ($target->type === 'layout') {
            $this->settings()->forceFill([(string) $target->field => $clean])->save();
        } elseif ($target->type === 'template') {
            $this->collectionOf($target)->forceFill(['site_template' => $clean])->save();
        } else {
            $target->pattern?->update(['tree' => $clean]);
        }

        return $clean;
    }

    /**
     * A human-readable name of the target, for the activity log.
     */
    public function label(BlockTarget $target): string
    {
        return match ($target->type) {
            'field' => $this->collectionOf($target)->label." #{$target->recordId}".($target->locale ? " ({$target->locale})" : ''),
            'template' => 'Шаблон страницы: '.$this->collectionOf($target)->label,
            'layout' => $target->field === 'header' ? 'Шапка сайта' : 'Подвал сайта',
            default => 'Секция: '.($target->pattern->name ?? ''),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sanitize(mixed $tree): array
    {
        $collections = Collection::query()->pluck('name')->map(fn (mixed $name) => (string) $name)->all();

        return $this->registry->sanitize($tree, 'tree', $collections);
    }

    /**
     * @param  list<string>  $parts
     */
    private function field(string $key, array $parts, ?string $locale = null): BlockTarget
    {
        $collection = $this->collection($parts[1] ?? '');
        $recordId = (int) ($parts[2] ?? 0);
        $field = $collection->field($parts[3] ?? '');

        if (! $field || $field->type !== FieldType::Blocks) {
            throw ValidationException::withMessages(['target' => 'Это поле не содержит блоков.']);
        }

        abort_unless((new RecordRepository($collection))->exists($recordId), 404);

        return new BlockTarget($key, 'field', $collection, $recordId, $field->name, locale: $locale);
    }

    private function collection(string $name): Collection
    {
        return Collection::query()->where('name', $name)->firstOrFail();
    }

    private function collectionOf(BlockTarget $target): Collection
    {
        return $target->collection ?? throw new \LogicException('The target has no collection.');
    }
}

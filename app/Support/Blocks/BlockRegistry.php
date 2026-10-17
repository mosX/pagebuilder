<?php

namespace App\Support\Blocks;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Block definitions shared with the frontend: resources/js/blocks/<name>/block.json.
 *
 * Every block tree saved by users goes through sanitize(): unknown blocks are rejected,
 * props are reduced to the declared ones and coerced to their types, and URLs are limited
 * to safe schemes, so stored trees are always renderable and never carry scripts.
 */
class BlockRegistry
{
    public const MAX_DEPTH = 8;

    public const MAX_NODES = 500;

    public const DEVICES = ['mobile', 'tablet', 'desktop'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    private int $nodes = 0;

    public function __construct(private string $path) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = [];

        foreach (glob($this->path.'/*/block.json') ?: [] as $file) {
            $definition = json_decode((string) file_get_contents($file), true);

            if (is_array($definition) && is_string($definition['name'] ?? null)) {
                $this->definitions[$definition['name']] = $definition;
            }
        }

        return $this->definitions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * @param  array<int, string>  $collections  System names of collections the tree may reference.
     * @return list<array<string, mixed>>
     */
    public function sanitize(mixed $tree, string $attribute, array $collections = []): array
    {
        if ($tree === null) {
            return [];
        }

        if (! is_array($tree) || ! array_is_list($tree)) {
            $this->fail($attribute, 'Ожидается список блоков.');
        }

        $this->nodes = 0;

        return $this->sanitizeList($tree, $attribute, $collections, 1);
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, string>  $collections
     * @return list<array<string, mixed>>
     */
    private function sanitizeList(array $nodes, string $attribute, array $collections, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail($attribute, 'Слишком глубокая вложенность блоков.');
        }

        $result = [];

        foreach ($nodes as $node) {
            if (++$this->nodes > self::MAX_NODES) {
                $this->fail($attribute, 'Слишком много блоков на странице.');
            }

            $result[] = $this->sanitizeNode($node, $attribute, $collections, $depth);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $collections
     * @return array<string, mixed>
     */
    public function sanitizeNode(mixed $node, string $attribute, array $collections, int $depth = 1): array
    {
        $type = is_array($node) ? ($node['type'] ?? null) : null;
        $definition = is_string($type) ? $this->get($type) : null;

        // Service blocks (search results, account forms) live on generated pages only.
        if (! is_array($node) || ! $definition || ! empty($definition['hidden'])) {
            $this->fail($attribute, 'Неизвестный тип блока: '.(is_string($type) ? $type : '?').'.');
        }

        $id = $node['id'] ?? null;
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        /** @var array<string, array<string, mixed>> $schema */
        $schema = is_array($definition['props'] ?? null) ? $definition['props'] : [];

        $cleanProps = [];

        foreach ($schema as $key => $prop) {
            $cleanProps[$key] = $this->sanitizeProp($prop, $props[$key] ?? null, $collections);
        }

        $clean = [
            'id' => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) ? $id : Str::random(10),
            'type' => $type,
            // An object, so blocks without props are stored as {} rather than [].
            'props' => (object) $cleanProps,
        ];

        // Devices the block is hidden on.
        $hide = is_array($node['hide'] ?? null) ? array_values(array_unique(array_intersect($node['hide'], self::DEVICES))) : [];

        if ($hide !== []) {
            $clean['hide'] = $hide;
        }

        if (! empty($definition['children'])) {
            $children = $node['children'] ?? [];
            $clean['children'] = is_array($children) && array_is_list($children)
                ? $this->sanitizeList($children, $attribute, $collections, $depth + 1)
                : [];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @param  array<int, string>  $collections
     */
    private function sanitizeProp(array $prop, mixed $value, array $collections): mixed
    {
        $default = $prop['default'] ?? null;

        return match ($prop['type'] ?? null) {
            'text' => is_scalar($value) ? Str::limit((string) $value, (int) ($prop['max'] ?? 500), '') : $default,
            'richtext' => (new RichText($this))->sanitize($value ?? $default),
            'textarea' => is_scalar($value) ? Str::limit((string) $value, (int) ($prop['max'] ?? 20000), '') : $default,
            'number' => is_numeric($value) ? $this->number($prop, $value) : $default,
            'boolean' => is_bool($value) ? $value : (bool) $default,
            'select' => in_array($value, array_column((array) ($prop['options'] ?? []), 'value'), true) ? $value : $default,
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : ($value === '' ? '' : $default),
            'url', 'image', 'file' => is_string($value) ? $this->safeUrl($value) : $default,
            'pattern' => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            'collection' => is_string($value) && in_array($value, $collections, true) ? $value : '',
            'fields' => is_array($value) ? array_slice(array_values(array_unique(array_filter($value, fn ($v) => is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $v)))), 0, 30) : [],
            'field', 'record_field' => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $value) ? $value : '',
            'list' => $this->sanitizeItems($prop, $value, $collections),
            default => $default,
        };
    }

    /**
     * A repeatable group of props (FAQ items, plans, gallery images): every item is
     * sanitized with the item schema; items cannot contain lists themselves.
     *
     * @param  array<string, mixed>  $prop
     * @param  array<int, string>  $collections
     * @return list<array<string, mixed>>
     */
    private function sanitizeItems(array $prop, mixed $value, array $collections): array
    {
        /** @var array<string, array<string, mixed>> $schema */
        $schema = array_filter((array) ($prop['item'] ?? []), fn ($item) => is_array($item) && ($item['type'] ?? null) !== 'list');
        $items = is_array($value) && array_is_list($value) ? $value : (array) ($prop['default'] ?? []);
        $result = [];

        foreach (array_slice(array_filter($items, 'is_array'), 0, (int) ($prop['max'] ?? 50)) as $item) {
            $clean = [];

            foreach ($schema as $key => $itemProp) {
                $clean[$key] = $this->sanitizeProp($itemProp, $item[$key] ?? null, $collections);
            }

            $result[] = $clean;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $prop
     */
    private function number(array $prop, mixed $value): int|float
    {
        $number = (float) $value;
        $min = isset($prop['min']) && is_numeric($prop['min']) ? (float) $prop['min'] : null;
        $max = isset($prop['max']) && is_numeric($prop['max']) ? (float) $prop['max'] : null;

        $number = $min !== null ? max($min, $number) : $number;
        $number = $max !== null ? min($max, $number) : $number;

        return fmod((float) ($prop['step'] ?? 1), 1.0) === 0.0 ? (int) round($number) : $number;
    }

    /**
     * Allows relative links, anchors and http(s)/mailto/tel URLs only.
     */
    public function safeUrl(string $url): string
    {
        $url = trim(Str::limit($url, 2048, ''));

        if ($url === '' || str_starts_with($url, '#') || (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\'))) {
            return $url;
        }

        return preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url) ? $url : '';
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}

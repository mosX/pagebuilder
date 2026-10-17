<?php

namespace App\Support\Blocks;

use Illuminate\Support\Str;

/**
 * Formatted text of blocks, stored as a ProseMirror/Tiptap JSON document.
 *
 * Only an allow-list of nodes and marks survives, links are limited to safe URLs,
 * and the frontend renders the document as elements (never as HTML strings),
 * so formatted text cannot carry markup or scripts.
 */
class RichText
{
    private const MAX_DEPTH = 12;

    private const MAX_TEXT = 50000;

    /** Node types and the attributes they keep. */
    private const NODES = [
        'paragraph' => [],
        'heading' => ['level'],
        'bulletList' => [],
        'orderedList' => ['start'],
        'listItem' => [],
        'blockquote' => [],
        'hardBreak' => [],
        'horizontalRule' => [],
    ];

    private const MARKS = ['bold', 'italic', 'strike', 'underline', 'code', 'link'];

    private int $length = 0;

    public function __construct(private BlockRegistry $registry) {}

    /**
     * The words of a document (or a legacy plain string), for search and excerpts.
     */
    public static function plainText(mixed $value): string
    {
        if (is_string($value)) {
            return html_entity_decode(strip_tags($value));
        }

        if (! is_array($value)) {
            return '';
        }

        $text = is_string($value['text'] ?? null) ? $value['text'] : '';

        foreach (is_array($value['content'] ?? null) ? $value['content'] : [] as $child) {
            $text .= ' '.self::plainText($child);
        }

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitize(mixed $value): array
    {
        $this->length = 0;

        // Plain text (older blocks): a blank line starts a new paragraph.
        if (is_string($value)) {
            return self::fromText($value);
        }

        $content = is_array($value) && ($value['type'] ?? null) === 'doc' && is_array($value['content'] ?? null)
            ? $this->nodes($value['content'], 1)
            : [];

        return ['type' => 'doc', 'content' => $content ?: [['type' => 'paragraph']]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromText(string $text): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: [])));

        return [
            'type' => 'doc',
            'content' => $paragraphs
                ? array_map(fn (string $p) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => Str::limit($p, self::MAX_TEXT, '')]]], $paragraphs)
                : [['type' => 'paragraph']],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodes(array $nodes, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $result = [];

        foreach ($nodes as $node) {
            $clean = is_array($node) ? $this->node($node, $depth) : null;

            if ($clean !== null) {
                $result[] = $clean;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function node(array $node, int $depth): ?array
    {
        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return $this->text($node);
        }

        if (! is_string($type) || ! array_key_exists($type, self::NODES)) {
            return null;
        }

        $clean = ['type' => $type];
        $attrs = $this->attrs($type, is_array($node['attrs'] ?? null) ? $node['attrs'] : []);

        if ($attrs) {
            $clean['attrs'] = $attrs;
        }

        if (is_array($node['content'] ?? null) && ! in_array($type, ['hardBreak', 'horizontalRule'], true)) {
            $clean['content'] = $this->nodes($node['content'], $depth + 1);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function text(array $node): ?array
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        $text = Str::limit($text, max(0, self::MAX_TEXT - $this->length), '');
        $this->length += mb_strlen($text);

        if ($text === '') {
            return null;
        }

        $marks = [];

        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! in_array($type, self::MARKS, true)) {
                continue;
            }

            if ($type === 'link') {
                $href = $this->registry->safeUrl(is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : '');

                if ($href !== '') {
                    $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }

                continue;
            }

            $marks[] = ['type' => $type];
        }

        return $marks ? ['type' => 'text', 'text' => $text, 'marks' => $marks] : ['type' => 'text', 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, int>
     */
    private function attrs(string $type, array $attrs): array
    {
        return match ($type) {
            'heading' => ['level' => min(4, max(2, is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2))],
            'orderedList' => is_numeric($attrs['start'] ?? null) && (int) $attrs['start'] > 1 ? ['start' => min(10000, (int) $attrs['start'])] : [],
            default => [],
        };
    }
}

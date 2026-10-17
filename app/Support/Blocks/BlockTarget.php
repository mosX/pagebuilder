<?php

namespace App\Support\Blocks;

use App\Enums\Permission;
use App\Models\BlockPattern;
use App\Models\Collection;

/**
 * Something edited in the block constructor, addressed by a key:
 * - field:{collection}:{record id}:{field}  a blocks field of a record (e.g. a page)
 * - template:{collection}                    the record page template of a collection
 * - pattern:{id}                             a reusable section
 * - layout:header|footer                     the site header or footer
 * A field key may end with @{locale}: the field's translation into that language.
 */
final readonly class BlockTarget
{
    public function __construct(
        public string $key,
        public string $type,
        public ?Collection $collection = null,
        public ?int $recordId = null,
        public ?string $field = null,
        public ?BlockPattern $pattern = null,
        public ?string $locale = null,
    ) {}

    /**
     * Editing a template changes the site structure; everything else is content.
     */
    public function permission(): Permission
    {
        return $this->type === 'template' ? Permission::SchemaManage : Permission::ContentManage;
    }
}

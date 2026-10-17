<?php

namespace App\Support\Blocks;

use App\Models\Collection;
use App\Models\Tenant;

/**
 * Where a block tree is rendered: the company, the site's base URL for links,
 * the current record (record page templates), the requested list page and the language.
 */
final readonly class RenderContext
{
    /**
     * @param  string  $baseUrl  '' on the company subdomain, '/site/{slug}' on the preview path.
     * @param  array<string, mixed>|null  $record  The record a template renders, as returned by RecordRepository.
     */
    public function __construct(
        public Tenant $tenant,
        public string $baseUrl = '',
        public ?Collection $collection = null,
        public ?array $record = null,
        public int $page = 1,
        /** How many sections (patterns) deep the current tree is nested. */
        public int $depth = 0,
        /** Editor preview: nothing that grants public write access (form tokens) is issued. */
        public bool $preview = false,
        /** Language of the content; null for the site's default language. */
        public ?string $locale = null,
    ) {}

    public function nested(): self
    {
        return new self($this->tenant, $this->baseUrl, $this->collection, $this->record, $this->page, $this->depth + 1, $this->preview, $this->locale);
    }
}

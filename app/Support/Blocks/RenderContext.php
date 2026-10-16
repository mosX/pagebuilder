<?php

namespace App\Support\Blocks;

use App\Models\Collection;
use App\Models\Tenant;

/**
 * Where a block tree is rendered: the company, the site's base URL for links,
 * the current record (record page templates) and the requested list page.
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
    ) {}
}

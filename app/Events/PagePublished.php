<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A block tree went live: a page, a record template, a section, the header or footer.
 */
class PagePublished
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant,
        public string $target,
        public string $label,
    ) {}

    public function name(): string
    {
        return 'page.published';
    }
}

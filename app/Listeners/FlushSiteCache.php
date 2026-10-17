<?php

namespace App\Listeners;

use App\Events\PagePublished;
use App\Events\RecordEvent;
use App\Support\Site\SiteCache;

/**
 * Record changes from any source (admin, headless API, forms, automations) and
 * publishing make the company's cached site pages stale.
 */
class FlushSiteCache
{
    public function __construct(private SiteCache $cache) {}

    public function onEvent(RecordEvent|PagePublished $event): void
    {
        $this->cache->flush($event instanceof PagePublished ? $event->tenant->id : $event->collection->tenant_id);
    }
}

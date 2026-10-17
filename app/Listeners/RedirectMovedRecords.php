<?php

namespace App\Listeners;

use App\Events\RecordUpdated;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Site\RecordUrls;
use App\Support\Site\SiteRedirects;

/**
 * A page or a record page got a new address: the old one redirects to it,
 * so links from search engines, social networks and other sites keep working.
 */
class RedirectMovedRecords
{
    public function __construct(
        private SiteRedirects $redirects,
        private RecordUrls $urls,
    ) {}

    public function handle(RecordUpdated $event): void
    {
        $collection = $event->collection;
        $field = $collection->kind === PagesCollection::KIND ? 'slug' : $collection->site_slug_field;

        if (! $field || $field === 'id' || ! in_array($field, $event->changes, true)) {
            return;
        }

        $tenant = Tenant::find($collection->tenant_id);

        if ($collection->kind === PagesCollection::KIND) {
            $old = $event->before['slug'] ?? null;
            $new = $event->record['slug'] ?? null;

            if ($tenant && is_string($old) && is_string($new) && $old !== PagesCollection::HOME_SLUG) {
                $this->redirects->moved($tenant, "/{$old}", $new === PagesCollection::HOME_SLUG ? '/' : "/{$new}");
            }

            return;
        }

        $old = $this->urls->record($collection, $event->before);
        $new = $this->urls->record($collection, $event->record);

        if ($tenant && $old && $new) {
            $this->redirects->moved($tenant, rawurldecode($old), rawurldecode($new));
        }
    }
}

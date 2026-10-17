<?php

namespace App\Listeners;

use App\Events\RecordUpdated;
use App\Models\Tenant;
use App\Support\Site\SiteAccounts;
use App\Support\Site\SiteDesign;

/**
 * When a submission's status changes (from the admin, the API or an automation),
 * its author gets an e-mail — if the company turned that on in the account settings.
 */
class NotifySubmissionStatus
{
    public function __construct(
        private SiteAccounts $accounts,
        private SiteDesign $design,
    ) {}

    public function handle(RecordUpdated $event): void
    {
        if ($event->changes === [] || $event->collection->kind !== null) {
            return;
        }

        $tenant = Tenant::find($event->collection->tenant_id);

        if ($tenant) {
            $this->accounts->statusChanged($event->collection, $event->record, $event->changes, $this->design->for($tenant), $tenant);
        }
    }
}

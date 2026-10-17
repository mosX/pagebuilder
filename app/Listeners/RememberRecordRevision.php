<?php

namespace App\Listeners;

use App\Events\RecordCreated;
use App\Events\RecordUpdated;
use App\Support\Collections\RecordHistory;

/**
 * Keeps a version of a record after it is created or changed, whatever made the change.
 */
class RememberRecordRevision
{
    public function __construct(private RecordHistory $history) {}

    public function handle(RecordCreated|RecordUpdated $event): void
    {
        $this->history->remember($event->collection, $event->record, $event->changes, $event->depth);
    }
}

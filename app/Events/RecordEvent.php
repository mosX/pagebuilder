<?php

namespace App\Events;

use App\Models\Collection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a record of a collection. Dispatched by RecordRepository,
 * so it fires for the admin, the headless API, forms and automations alike.
 *
 * Extension point: in a single-project install, listen to these events in code.
 */
abstract class RecordEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $record  The record as presented by RecordRepository.
     * @param  list<string>  $changes  Names of the fields that changed (updates only).
     * @param  int  $depth  How many automations deep this change was caused (loop protection).
     * @param  array<string, mixed>  $before  The record before the change (updates only).
     */
    public function __construct(
        public Collection $collection,
        public array $record,
        public array $changes = [],
        public int $depth = 0,
        public array $before = [],
    ) {}

    /**
     * The event name used by webhooks and automations, e.g. "record.created".
     */
    abstract public function name(): string;
}

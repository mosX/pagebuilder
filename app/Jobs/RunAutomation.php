<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Collection;
use App\Support\Automation\AutomationDepth;
use App\Support\Automation\AutomationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one automation for one event. Not retried: actions like e-mails must not repeat.
 */
class RunAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public int $automationId,
        public string $event,
        public int $collectionId,
        public array $record,
        public int $depth,
    ) {}

    public function handle(AutomationEngine $engine): void
    {
        $automation = Automation::withoutGlobalScope('tenant')->find($this->automationId);
        $collection = Collection::withoutGlobalScope('tenant')->find($this->collectionId);

        if (! $automation || ! $collection || ! $automation->is_active) {
            return;
        }

        $subject = "{$collection->label} #".($this->record['id'] ?? '?');

        try {
            // Changes made by the actions carry depth + 1 (loop protection).
            $done = AutomationDepth::run($this->depth + 1, fn () => $engine->run($automation, $this->event, $collection, $this->record));
            $this->log($automation, true, $subject, implode('; ', $done) ?: 'Нет действий');
        } catch (Throwable $e) {
            $this->log($automation, false, $subject, Str::limit($e->getMessage(), 900));
        }
    }

    private function log(Automation $automation, bool $success, string $subject, string $message): void
    {
        AutomationRun::create([
            'automation_id' => $automation->id,
            'success' => $success,
            'subject' => $subject,
            'message' => $message,
        ]);

        $automation->forceFill([
            'runs_count' => $automation->runs_count + 1,
            'last_run_at' => now(),
            'last_error' => $success ? null : Str::limit($message, 480),
        ])->saveQuietly();

        $keep = $automation->runs()->latest('id')->limit(AutomationRun::KEEP)->pluck('id');
        $automation->runs()->whereNotIn('id', $keep)->delete();
    }
}

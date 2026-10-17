<?php

namespace App\Listeners;

use App\Events\PagePublished;
use App\Events\RecordEvent;
use App\Jobs\DeliverWebhook;
use App\Jobs\RunAutomation;
use App\Models\Automation;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Support\Automation\AutomationDepth;
use App\Support\Automation\AutomationEngine;

/**
 * Turns company events into queued work: webhook deliveries and automation runs.
 * Registered explicitly in AppServiceProvider (the method is not called handle on purpose).
 */
class NotifyIntegrations
{
    public function __construct(private AutomationEngine $engine) {}

    public function onEvent(RecordEvent|PagePublished $event): void
    {
        if ($event instanceof PagePublished) {
            $this->webhooks($event->tenant, $event->name(), null, [
                'company' => $this->company($event->tenant),
                'target' => $event->target,
                'label' => $event->label,
            ]);

            return;
        }

        $collection = $event->collection;
        $tenant = Tenant::find($collection->tenant_id);

        if (! $tenant) {
            return;
        }

        $this->webhooks($tenant, $event->name(), $collection->name, [
            'company' => $this->company($tenant),
            'collection' => ['name' => $collection->name, 'label' => $collection->label],
            'record' => $event->record,
            'changes' => $event->changes,
        ]);

        // Automations started by automations stop at the depth limit.
        if ($event->depth >= AutomationDepth::MAX) {
            return;
        }

        $automations = Automation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        foreach ($automations as $automation) {
            if ($this->engine->matches($automation, $event->name(), $collection, $event->record, $event->changes)) {
                RunAutomation::dispatch($automation->id, $event->name(), $collection->id, $event->record, $event->depth);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhooks(Tenant $tenant, string $event, ?string $collection, array $payload): void
    {
        $webhooks = Webhook::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->listensTo($event, $collection)) {
                DeliverWebhook::dispatch($webhook->id, $event, $payload);
            }
        }
    }

    /**
     * @return array{slug: string, name: string}
     */
    private function company(Tenant $tenant): array
    {
        return ['slug' => $tenant->slug, 'name' => $tenant->name];
    }
}

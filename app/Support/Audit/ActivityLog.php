<?php

namespace App\Support\Audit;

use App\Models\Activity;
use App\Models\Tenant;
use App\Support\Site\SiteCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Writes the company's activity log: who changed what (schema, records, pages,
 * media, members, settings). Actions are short keys, e.g. "record.updated".
 * Every logged change also makes the company's cached site pages stale.
 */
class ActivityLog
{
    /** Set for requests made with an API key, so entries name the key. */
    private ?string $actor = null;

    public function __construct(private TenantContext $context) {}

    public function actAs(?string $actor): void
    {
        $this->actor = $actor;
    }

    /**
     * Who acts in this request when it is not a signed-in user (an API key).
     */
    public function actor(): ?string
    {
        return $this->actor;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, string $subject, array $details = [], ?Tenant $tenant = null, ?string $actor = null): void
    {
        $tenantId = $tenant->id ?? $this->context->id();

        if (! $tenantId) {
            return;
        }

        $request = request();
        $activity = new Activity;
        $activity->forceFill([
            'tenant_id' => $tenantId,
            'user_id' => $actor || $this->actor ? null : $request->user()?->getAuthIdentifier(),
            'actor' => $actor ?? $this->actor,
            'action' => $action,
            'subject' => Str::limit($subject, 250),
            'details' => $details ?: null,
            'ip' => $request->ip(),
        ])->save();

        app(SiteCache::class)->flush($tenantId);
    }
}

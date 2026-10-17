<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Audit\ActivityLog;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates headless API requests (Authorization: Bearer pb_…) and binds the key's
 * company to the TenantContext, so all lookups stay inside that company.
 */
class AuthenticateApiKey
{
    public function __construct(
        private TenantContext $context,
        private ActivityLog $activity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findByToken((string) $request->bearerToken());

        if (! $key || ! $key->tenant->is_active) {
            return response()->json(['message' => 'Неверный или отозванный API-ключ.'], 401);
        }

        $this->context->set($key->tenant);
        $this->activity->actAs("API: {$key->name}");
        $request->attributes->set('api_key', $key);

        // Avoid a write per request: last use is precise to a minute.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}

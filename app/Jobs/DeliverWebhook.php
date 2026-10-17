<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Automation\UrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends one event to one webhook: a JSON POST signed with HMAC-SHA256 of the body
 * (header X-Pagebuilder-Signature: sha256=…). Failed deliveries are retried with
 * growing pauses; every attempt is logged.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $webhookId,
        public string $event,
        public array $payload,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 1800];
    }

    public function handle(UrlGuard $guard): void
    {
        $webhook = Webhook::withoutGlobalScope('tenant')->find($this->webhookId);

        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        $error = self::send($guard, $webhook, $this->event, $this->payload, $this->attempts());

        // Let the queue retry (with backoff) unless this was the last attempt.
        if ($error !== null && $this->attempts() < $this->tries) {
            throw new RuntimeException($error);
        }
    }

    /**
     * Delivers and logs one attempt; returns the error, or null on success.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send(UrlGuard $guard, Webhook $webhook, string $event, array $payload, int $attempt = 1): ?string
    {
        $body = (string) json_encode([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            ...$payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $status = null;
        $error = null;

        try {
            $curl = $guard->assertPublic($webhook->url);

            $response = Http::timeout((int) config('webhooks.timeout', 10))
                ->withoutRedirecting()
                ->withOptions(['curl' => $curl])
                ->withHeaders([
                    'User-Agent' => 'Pagebuilder-Webhooks/1.0',
                    'X-Pagebuilder-Event' => $event,
                    'X-Pagebuilder-Signature' => 'sha256='.hash_hmac('sha256', $body, $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            $status = $response->status();
            $error = $response->successful() ? null : "Ответ {$status}";
        } catch (Throwable $e) {
            $error = Str::limit($e->getMessage(), 480);
        }

        self::log($webhook, $event, $payload, $attempt, $status, $error);

        return $error;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function log(Webhook $webhook, string $event, array $payload, int $attempt, ?int $status, ?string $error): void
    {
        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'status' => $status,
            'success' => $error === null,
            'attempt' => $attempt,
            'error' => $error,
        ]);

        $webhook->forceFill(['last_called_at' => now(), 'last_status' => $status ?? 0])->saveQuietly();

        $keep = $webhook->deliveries()->latest('id')->limit(WebhookDelivery::KEEP)->pluck('id');
        $webhook->deliveries()->whereNotIn('id', $keep)->delete();
    }
}

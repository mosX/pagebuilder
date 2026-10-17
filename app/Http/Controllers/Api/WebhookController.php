<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\Collection;
use App\Models\Webhook;
use App\Support\Audit\ActivityLog;
use App\Support\Automation\UrlGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Webhooks of the current company: URLs notified about events, with a delivery log.
 */
class WebhookController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private ActivityLog $activity,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(Webhook::query()->latest('id')->get()->map(fn (Webhook $w) => $this->present($w)));
    }

    public function store(Request $request): JsonResponse
    {
        $webhook = new Webhook;
        $webhook->forceFill([
            ...$this->validated($request),
            'tenant_id' => $this->context->tenant()->id,
            'secret' => Webhook::newSecret(),
        ])->save();

        $this->activity->record('webhook.created', $webhook->name);

        return response()->json([
            'message' => 'Вебхук создан.',
            'webhook' => $this->present($webhook),
            // Shown once, like API keys: needed to verify signatures.
            'secret' => $webhook->secret,
        ], 201);
    }

    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        $webhook->forceFill($this->validated($request))->save();
        $this->activity->record('webhook.updated', $webhook->name);

        return response()->json(['message' => 'Вебхук сохранён.', 'webhook' => $this->present($webhook)]);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();
        $this->activity->record('webhook.deleted', $webhook->name);

        return response()->json(['message' => "Вебхук «{$webhook->name}» удалён."]);
    }

    public function rotateSecret(Webhook $webhook): JsonResponse
    {
        $webhook->forceFill(['secret' => Webhook::newSecret()])->save();

        return response()->json(['message' => 'Новый секрет создан.', 'secret' => $webhook->secret]);
    }

    public function deliveries(Webhook $webhook): JsonResponse
    {
        return response()->json($webhook->deliveries()->latest('id')->limit(50)->get());
    }

    /**
     * Sends a "ping" right away, so the result is visible immediately.
     */
    public function test(Webhook $webhook, UrlGuard $guard): JsonResponse
    {
        $error = DeliverWebhook::send($guard, $webhook, 'ping', [
            'company' => ['slug' => $this->context->tenant()->slug, 'name' => $this->context->tenant()->name],
            'message' => 'Проверка вебхука',
        ]);

        return response()->json(
            ['message' => $error ? "Не доставлено: {$error}" : 'Доставлено.', 'success' => $error === null],
            $error ? 422 : 200,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(Webhook::EVENTS)],
            'collection' => ['nullable', 'string', Rule::in(Collection::query()->pluck('name')->all())],
            'is_active' => ['boolean'],
        ]);

        try {
            app(UrlGuard::class)->assertPublic($data['url']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return [
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'collection' => $data['collection'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'collection' => $webhook->collection,
            'is_active' => $webhook->is_active,
            'last_called_at' => $webhook->last_called_at?->toIso8601String(),
            'last_status' => $webhook->last_status,
        ];
    }
}

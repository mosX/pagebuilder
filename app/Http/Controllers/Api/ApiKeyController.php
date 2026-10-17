<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API keys of the current company for the headless API.
 */
class ApiKeyController extends Controller
{
    public function __construct(private ActivityLog $activity) {}

    public function index(): JsonResponse
    {
        return response()->json(
            ApiKey::query()->latest('id')->get()->map(fn (ApiKey $key) => $this->present($key)),
        );
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'can_write' => ['boolean'],
        ]);

        [$key, $token] = ApiKey::issue($context->tenant(), $data['name'], (bool) ($data['can_write'] ?? false), $request->user() instanceof User ? $request->user() : null);
        $this->activity->record('apikey.created', $key->name, ['can_write' => $key->can_write]);

        return response()->json([
            'message' => 'Ключ создан. Скопируйте его сейчас — позже его не будет видно.',
            'key' => $this->present($key),
            'token' => $token,
        ], 201);
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->delete();
        $this->activity->record('apikey.revoked', $apiKey->name);

        return response()->json(['message' => "Ключ «{$apiKey->name}» отозван."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'can_write' => $key->can_write,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}

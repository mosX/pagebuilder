<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CustomTemplate;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use App\Support\Site\SiteSnapshots;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Company sites saved as templates for new companies. A template belongs to the user who
 * saved it; super admins see all of them and may offer one to every user of the platform.
 */
class CustomTemplateController extends Controller
{
    public function __construct(private SiteSnapshots $snapshots) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $templates = CustomTemplate::query()
            ->when(! $user->is_super_admin, fn ($q) => $q->where('user_id', $user->id))
            ->with('user')
            ->latest('id')
            ->get()
            ->map(fn (CustomTemplate $t) => $this->present($t));

        return response()->json(['templates' => $templates, 'can_publish' => $user->is_super_admin]);
    }

    /**
     * Saves the current company's site as a template.
     */
    public function store(Request $request, TenantContext $context, ActivityLog $activity): JsonResponse
    {
        $names = Collection::query()->whereNull('kind')->pluck('name')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'record_collections' => ['present', 'array'],
            'record_collections.*' => ['string', Rule::in($names)],
        ]);

        $template = $this->snapshots->capture(
            $context->tenant(),
            $this->user($request),
            $data['name'],
            $data['description'] ?? null,
            array_values(array_unique($data['record_collections'])),
        );

        $activity->record('site.template_saved', $template->name);

        return response()->json([
            'message' => "Шаблон «{$template->name}» сохранён. Его можно выбрать при создании новой компании.",
            'template' => $this->present($template->load('user')),
        ], 201);
    }

    public function update(Request $request, CustomTemplate $customTemplate): JsonResponse
    {
        $user = $this->authorizeOwner($request, $customTemplate);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $customTemplate->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            // Offering a template to everyone is the platform's decision.
            'is_public' => $user->is_super_admin && array_key_exists('is_public', $data) ? $data['is_public'] : $customTemplate->is_public,
        ])->save();

        return response()->json(['message' => 'Шаблон сохранён.', 'template' => $this->present($customTemplate->load('user'))]);
    }

    public function destroy(Request $request, CustomTemplate $customTemplate): JsonResponse
    {
        $this->authorizeOwner($request, $customTemplate);
        $this->snapshots->delete($customTemplate);

        return response()->json(['message' => 'Шаблон удалён.']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function authorizeOwner(Request $request, CustomTemplate $template): User
    {
        $user = $this->user($request);
        abort_unless($user->is_super_admin || $template->user_id === $user->id, 404);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomTemplate $template): array
    {
        $data = $template->data;

        return [
            'id' => $template->id,
            'key' => $template->key(),
            'name' => $template->name,
            'description' => $template->description,
            'is_public' => $template->is_public,
            'owner' => $template->user?->name,
            'pages' => count((array) ($data['pages'] ?? [])),
            'collections' => count((array) ($data['schema']['collections'] ?? [])),
            'records' => array_sum(array_map(fn (mixed $set) => count((array) (is_array($set) ? ($set['rows'] ?? []) : [])), (array) ($data['records'] ?? []))),
            'files' => count((array) ($data['media'] ?? [])),
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Support\Audit\ActivityLog;
use App\Support\Site\SiteRedirects;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Redirects of the current company's site: the automatic ones (changed addresses)
 * and those added by hand (e.g. old URLs of a previous site).
 */
class RedirectController extends Controller
{
    public function __construct(
        private SiteRedirects $redirects,
        private ActivityLog $activity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->limit(100, '')->toString());

        $page = Redirect::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('from_path', 'like', '%'.addcslashes($search, '\\%_').'%')
                ->orWhere('to_path', 'like', '%'.addcslashes($search, '\\%_').'%')))
            ->latest('id')
            ->paginate(50);

        return response()->json($page);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        [$from, $to, $status] = $this->validated($request);

        if (Redirect::query()->where('from_path', $from)->exists()) {
            throw ValidationException::withMessages(['from_path' => 'Для этого адреса редирект уже есть.']);
        }

        if (Redirect::query()->count() >= SiteRedirects::MAX_PER_TENANT) {
            throw ValidationException::withMessages(['from_path' => 'Слишком много редиректов.']);
        }

        $redirect = new Redirect;
        $redirect->forceFill(['tenant_id' => $context->tenant()->id, 'from_path' => $from, 'to_path' => $to, 'status' => $status])->save();
        $this->activity->record('redirect.created', "{$from} → {$to}");

        return response()->json(['message' => 'Редирект добавлен.', 'redirect' => $redirect], 201);
    }

    public function update(Request $request, Redirect $redirect): JsonResponse
    {
        [$from, $to, $status] = $this->validated($request);

        if (Redirect::query()->where('from_path', $from)->whereKeyNot($redirect->id)->exists()) {
            throw ValidationException::withMessages(['from_path' => 'Для этого адреса редирект уже есть.']);
        }

        $redirect->forceFill(['from_path' => $from, 'to_path' => $to, 'status' => $status, 'is_auto' => false])->save();
        $this->activity->record('redirect.updated', "{$from} → {$to}");

        return response()->json(['message' => 'Редирект сохранён.', 'redirect' => $redirect]);
    }

    public function destroy(Redirect $redirect): JsonResponse
    {
        $redirect->delete();
        $this->activity->record('redirect.deleted', "{$redirect->from_path} → {$redirect->to_path}");

        return response()->json(['message' => 'Редирект удалён.']);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:255'],
            'to_path' => ['required', 'string', 'max:1024'],
            'status' => ['sometimes', 'integer', Rule::in([301, 302])],
        ]);

        $from = $this->redirects->normalizeFrom($data['from_path']);
        $to = $this->redirects->normalizeTo($data['to_path']);

        if (! $from) {
            throw ValidationException::withMessages(['from_path' => 'Старый адрес — путь на сайте, например /old-page или /blog/* для целого раздела.']);
        }

        if (! $to) {
            throw ValidationException::withMessages(['to_path' => 'Новый адрес — путь на сайте (/new-page) или ссылка https://…']);
        }

        if ($from === $to) {
            throw ValidationException::withMessages(['to_path' => 'Адреса совпадают.']);
        }

        return [$from, $to, (int) ($data['status'] ?? 301)];
    }
}

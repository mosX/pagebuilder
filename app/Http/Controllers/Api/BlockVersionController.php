<?php

namespace App\Http\Controllers\Api;

use App\Events\PagePublished;
use App\Http\Controllers\Controller;
use App\Models\BlockVersion;
use App\Support\Audit\ActivityLog;
use App\Support\Blocks\BlockTarget;
use App\Support\Blocks\BlockTargets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Drafts and revisions of block trees. The constructor saves drafts (invisible on the site)
 * and publishes through here, which keeps a revision of every published version.
 */
class BlockVersionController extends Controller
{
    public const KEEP_REVISIONS = 30;

    public function __construct(
        private BlockTargets $targets,
        private TenantContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $target = $this->target($request);

        $draft = $this->versions($target, BlockVersion::DRAFT)->with('user')->first();
        $revisions = $this->versions($target, BlockVersion::REVISION)->with('user')->latest('id')->get();

        return response()->json([
            'published' => $this->targets->read($target),
            'draft' => $draft ? [
                'tree' => $draft->tree,
                'updated_at' => $draft->updated_at?->toIso8601String(),
                'user' => $draft->user?->name,
            ] : null,
            'revisions' => $revisions->map(fn (BlockVersion $v) => [
                'id' => $v->id,
                'created_at' => $v->created_at?->toIso8601String(),
                'user' => $v->user?->name,
            ]),
        ]);
    }

    public function revision(Request $request, BlockVersion $version): JsonResponse
    {
        abort_unless($version->kind === BlockVersion::REVISION, 404);
        $this->authorizeTarget($this->targets->resolve($version->target));

        return response()->json(['tree' => $version->tree]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $tree = $this->targets->sanitize($request->input('tree'));

        $draft = $this->versions($target, BlockVersion::DRAFT)->first() ?? new BlockVersion;
        $draft->forceFill([
            'target' => $target->key,
            'kind' => BlockVersion::DRAFT,
            'tree' => $tree,
            'user_id' => $request->user()?->id,
        ])->save();

        return response()->json(['message' => 'Черновик сохранён.', 'tree' => $tree]);
    }

    public function discardDraft(Request $request): JsonResponse
    {
        $this->versions($this->target($request), BlockVersion::DRAFT)->delete();

        return response()->json(['message' => 'Черновик удалён.']);
    }

    public function publish(Request $request): JsonResponse
    {
        $target = $this->target($request);

        $tree = DB::transaction(function () use ($request, $target) {
            $tree = $this->targets->write($target, $request->input('tree'));

            $revision = new BlockVersion;
            $revision->forceFill([
                'target' => $target->key,
                'kind' => BlockVersion::REVISION,
                'tree' => $tree,
                'user_id' => $request->user()?->id,
            ])->save();

            $this->versions($target, BlockVersion::DRAFT)->delete();

            // Keep the most recent revisions only.
            $keep = $this->versions($target, BlockVersion::REVISION)->latest('id')->limit(self::KEEP_REVISIONS)->pluck('id');
            $this->versions($target, BlockVersion::REVISION)->whereNotIn('id', $keep)->delete();

            return $tree;
        });

        app(ActivityLog::class)->record('blocks.published', $this->targets->label($target));

        PagePublished::dispatch($this->context->tenant(), $target->key, $this->targets->label($target));

        return response()->json(['message' => 'Опубликовано.', 'tree' => $tree]);
    }

    private function target(Request $request): BlockTarget
    {
        $request->validate([
            'target' => ['required', 'string', 'max:191'],
            'tree' => ['sometimes', 'array'],
        ]);

        $target = $this->targets->resolve($request->string('target')->value());
        $this->authorizeTarget($target);

        return $target;
    }

    private function authorizeTarget(BlockTarget $target): void
    {
        Gate::authorize($target->permission()->value);
    }

    /**
     * @return Builder<BlockVersion>
     */
    private function versions(BlockTarget $target, string $kind): Builder
    {
        return BlockVersion::query()->where('target', $target->key)->where('kind', $kind);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockPattern;
use App\Models\BlockVersion;
use App\Support\Audit\ActivityLog;
use App\Support\Blocks\BlockTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable sections of the current company. Their trees are edited in the
 * constructor and published through BlockVersionController (target pattern:{id}).
 */
class BlockPatternController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            BlockPattern::query()->orderBy('name')->get(['id', 'name', 'updated_at']),
        );
    }

    public function store(Request $request, BlockTargets $targets): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tree' => ['present', 'array'],
        ]);

        $pattern = new BlockPattern(['name' => $data['name'], 'tree' => $targets->sanitize($data['tree'])]);
        $pattern->save();
        app(ActivityLog::class)->record('pattern.created', $pattern->name);

        return response()->json(['message' => "Секция «{$pattern->name}» сохранена.", 'pattern' => $pattern], 201);
    }

    public function update(Request $request, BlockPattern $pattern): JsonResponse
    {
        $pattern->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]));

        app(ActivityLog::class)->record('pattern.renamed', $pattern->name);

        return response()->json(['message' => 'Секция переименована.', 'pattern' => $pattern]);
    }

    public function destroy(BlockPattern $pattern): JsonResponse
    {
        BlockVersion::query()->where('target', "pattern:{$pattern->id}")->delete();
        $pattern->delete();
        app(ActivityLog::class)->record('pattern.deleted', $pattern->name);

        return response()->json(['message' => "Секция «{$pattern->name}» удалена. На страницах она больше не отображается."]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Support\Blocks\BlockDataResolver;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\RenderContext;
use App\Support\Collections\RecordRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editor support: resolves the data a data block (e.g. a records list) would show,
 * so the canvas previews real company data while the page is being edited.
 * Record templates pass the collection and a sample record to preview with.
 */
class BlockController extends Controller
{
    public function resolve(
        Request $request,
        TenantContext $context,
        BlockRegistry $registry,
        BlockDataResolver $resolver,
    ): JsonResponse {
        $request->validate([
            'type' => ['required', 'string'],
            'props' => ['nullable', 'array'],
            'collection' => ['nullable', 'string'],
            'record_id' => ['nullable', 'integer'],
        ]);

        $tenant = $context->tenant();
        $collections = Collection::query()->pluck('name')->map(fn (mixed $name) => (string) $name)->all();
        $node = $registry->sanitizeNode($request->only(['type', 'props']), 'type', $collections);

        $collection = $request->filled('collection')
            ? Collection::query()->where('name', $request->string('collection')->value())->first()
            : null;

        $record = $collection && $request->filled('record_id')
            ? (new RecordRepository($collection))->find($request->integer('record_id'))
            : null;

        $render = new RenderContext($tenant, $tenant->siteUrl(), $collection, $record, preview: true);

        return response()->json(['data' => $resolver->resolve($render, $node)]);
    }
}

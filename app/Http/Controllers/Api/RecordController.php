<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Support\Collections\RecordRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records of a collection, stored in the collection's own table.
 */
class RecordController extends Controller
{
    public function index(Request $request, Collection $collection): JsonResponse
    {
        $records = (new RecordRepository($collection))->paginate([
            'search' => $request->string('search')->value(),
            'sort' => $request->string('sort')->value(),
            'direction' => $request->string('direction')->value(),
            'per_page' => $request->integer('per_page', 20),
        ]);

        return response()->json($records);
    }

    public function options(Request $request, Collection $collection): JsonResponse
    {
        return response()->json(
            (new RecordRepository($collection))->options($request->string('search')->value()),
        );
    }

    public function show(Collection $collection, int $record): JsonResponse
    {
        return response()->json((new RecordRepository($collection))->find($record) ?? abort(404));
    }

    public function store(Request $request, Collection $collection): JsonResponse
    {
        $record = (new RecordRepository($collection))->create($request->all());

        return response()->json(['message' => 'Запись создана.', 'record' => $record], 201);
    }

    public function update(Request $request, Collection $collection, int $record): JsonResponse
    {
        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        return response()->json(['message' => 'Запись сохранена.', 'record' => $repository->update($record, $request->all())]);
    }

    public function destroy(Collection $collection, int $record): JsonResponse
    {
        $repository = new RecordRepository($collection);
        abort_unless($repository->exists($record), 404);

        $repository->delete($record);

        return response()->json(['message' => 'Запись удалена.']);
    }
}

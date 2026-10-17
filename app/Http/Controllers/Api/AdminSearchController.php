<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockPattern;
use App\Models\Collection;
use App\Models\Media;
use App\Support\Collections\RecordRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quick search of the admin (Ctrl+K): records of every collection, collections,
 * saved sections and media files of the current company.
 */
class AdminSearchController extends Controller
{
    private const PER_COLLECTION = 3;

    private const MAX_RECORDS = 15;

    /** Collections searched per request: bounds the work for companies with many of them. */
    private const MAX_COLLECTIONS = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->limit(100, '')->toString());

        if (mb_strlen($query) < 2) {
            return response()->json(['records' => [], 'collections' => [], 'patterns' => [], 'media' => []]);
        }

        $like = '%'.addcslashes($query, '\\%_').'%';
        $collections = Collection::query()->with('fields')->orderByRaw('kind is null')->orderBy('label')->limit(self::MAX_COLLECTIONS)->get();

        return response()->json([
            'records' => $this->records($collections, $query),
            'collections' => $collections
                ->filter(fn (Collection $c) => mb_stripos($c->label, $query) !== false || str_contains($c->name, mb_strtolower($query)))
                ->map(fn (Collection $c) => ['name' => $c->name, 'label' => $c->label])
                ->values(),
            'patterns' => BlockPattern::query()->where('name', 'like', $like)->limit(5)->get(['id', 'name']),
            'media' => Media::query()->where('original_name', 'like', $like)->latest('id')->limit(5)->get()
                ->map(fn (Media $m) => ['id' => $m->id, 'name' => $m->original_name, 'url' => $m->url(), 'kind' => $m->kind()]),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Collection>  $collections
     * @return list<array{collection: string, collection_label: string, id: int, title: string}>
     */
    private function records(\Illuminate\Support\Collection $collections, string $query): array
    {
        $results = [];

        foreach ($collections as $collection) {
            $repository = new RecordRepository($collection);
            $display = $repository->displayField();

            foreach ($repository->paginate(['search' => $query, 'per_page' => self::PER_COLLECTION])->items() as $record) {
                $title = $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : '';

                $results[] = [
                    'collection' => $collection->name,
                    'collection_label' => $collection->label,
                    'id' => (int) $record['id'],
                    'title' => $title !== '' ? $title : "#{$record['id']}",
                ];

                if (count($results) >= self::MAX_RECORDS) {
                    return $results;
                }
            }
        }

        return $results;
    }
}

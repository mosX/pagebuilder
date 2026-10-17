<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteDesign;
use App\Support\Site\SiteLanguages;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Translations of a record's text and block fields into the site's other languages.
 */
class TranslationController extends Controller
{
    public function __construct(
        private SiteLanguages $languages,
        private SiteDesign $design,
        private TenantContext $context,
    ) {}

    public function show(Collection $collection, int $record): JsonResponse
    {
        abort_unless((new RecordRepository($collection))->exists($record), 404);

        return response()->json([
            'languages' => $this->languages->of($this->design->for($this->context->tenant())),
            'locales' => SiteLanguages::LOCALES,
            'fields' => $this->languages->translatableFields($collection)
                ->map(fn (CollectionField $f) => ['name' => $f->name, 'label' => $f->label, 'type' => $f->type->value])
                ->values(),
            'translations' => (object) $this->languages->forRecord($collection, $record),
        ]);
    }

    public function update(Request $request, Collection $collection, int $record, string $locale, ActivityLog $activity): JsonResponse
    {
        abort_unless((new RecordRepository($collection))->exists($record), 404);

        $request->merge(['locale' => $locale]);
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(SiteLanguages::LOCALES))],
            'values' => ['present', 'array'],
        ]);

        $stored = $this->languages->save($collection, $record, $locale, $data['values']);
        $activity->record('record.translated', "{$collection->label} #{$record}", ['locale' => $locale]);

        return response()->json([
            'message' => 'Перевод сохранён.',
            'values' => (object) $stored,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit\ActivityLog;
use App\Support\Collections\SchemaTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Export and import of the collections structure (no data).
 */
class SchemaController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private SchemaTransfer $transfer,
    ) {}

    public function export(): JsonResponse
    {
        $tenant = $this->context->tenant();

        return response()
            ->json($this->transfer->export($tenant), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ->header('Content-Disposition', "attachment; filename=\"schema-{$tenant->slug}.json\"");
    }

    public function import(Request $request, ActivityLog $activity): JsonResponse
    {
        $request->validate(['schema' => ['required', 'array']]);

        $report = $this->transfer->import($this->context->tenant(), (array) $request->input('schema'));
        $activity->record('schema.imported', 'Схема коллекций', [
            'created' => count($report['created']),
            'skipped' => count($report['skipped']),
            'errors' => count($report['errors']),
        ]);

        return response()->json(['message' => 'Импорт завершён.', 'report' => $report]);
    }
}

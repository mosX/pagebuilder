<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Site\SiteStats;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visit statistics of the current company's site.
 */
class SiteStatsController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public function __invoke(Request $request, SiteStats $stats, TenantContext $context): JsonResponse
    {
        $days = in_array($request->integer('days'), self::PERIODS, true) ? $request->integer('days') : 30;

        return response()->json([...$stats->summary($context->tenant()->id, $days), 'period' => $days]);
    }
}

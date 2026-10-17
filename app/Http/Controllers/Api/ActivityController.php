<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current company's activity log.
 */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $action = $request->string('action')->value();
        $search = $request->string('search')->trim()->value();

        $entries = Activity::query()
            ->with('user:id,name,email')
            ->when($action, fn ($q) => $q->where('action', 'like', "{$action}%"))
            ->when($search, fn ($q) => $q->where('subject', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(50)
            ->through(fn (Activity $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'subject' => $a->subject,
                'details' => $a->details,
                'actor' => $a->user->name ?? $a->actor ?? '—',
                'ip' => $a->ip,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json($entries);
    }
}

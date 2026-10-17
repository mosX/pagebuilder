<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\BlockVersion;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Blocks\BlockTargets;
use App\Support\Collections\PagesCollection;
use App\Support\Site\Scheduling;
use App\Support\Site\SiteStats;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The company's start page: site traffic, new form submissions, unpublished drafts,
 * scheduled publications and recent activity. Each part is shown only to roles that may see it.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, SiteStats $stats, BlockTargets $targets): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $context->tenant();
        $content = Gate::allows('content.view');

        return response()->json([
            'site_url' => $tenant->siteUrl(),
            'pages_collection' => app(PagesCollection::class)->find($tenant)?->name,
            'members' => $tenant->members()->count(),
            'tenants' => $user->is_super_admin ? Tenant::count() : null,
            'users' => $user->is_super_admin ? User::count() : null,
            'visits' => $content ? $this->visits($tenant, $stats) : null,
            'submissions' => $content ? $this->submissions($tenant) : null,
            'drafts' => $content ? $this->drafts($targets) : null,
            'scheduled' => $content ? $this->scheduled($tenant) : null,
            'activity' => Gate::allows('audit.view') ? $this->activity() : null,
        ]);
    }

    /**
     * The last 7 days and the change against the 7 days before.
     *
     * @return array<string, mixed>
     */
    private function visits(Tenant $tenant, SiteStats $stats): array
    {
        $fortnight = $stats->summary($tenant->id, 14)['days'];
        $week = array_slice($fortnight, 7);
        $previous = array_sum(array_column(array_slice($fortnight, 0, 7), 'views'));
        $views = array_sum(array_column($week, 'views'));

        return [
            'days' => $week,
            'views' => $views,
            'visitors' => array_sum(array_column($week, 'visitors')),
            'change' => $previous > 0 ? (int) round(($views - $previous) / $previous * 100) : null,
        ];
    }

    /**
     * Records created by site forms in the last 7 days.
     *
     * @return array<string, mixed>
     */
    private function submissions(Tenant $tenant): array
    {
        $query = Activity::query()->where('action', 'form.submitted')->where('created_at', '>=', now()->subDays(7));

        return [
            'count' => (clone $query)->count(),
            'latest' => $query->latest('id')->limit(5)->get()->map(fn (Activity $a) => [
                'subject' => $a->subject,
                'collection' => $a->details['collection'] ?? null,
                'record' => $a->details['record'] ?? null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * Saved drafts that are not on the site yet, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function drafts(BlockTargets $targets): array
    {
        $drafts = [];

        foreach (BlockVersion::query()->where('kind', BlockVersion::DRAFT)->with('user')->latest('updated_at')->limit(5)->get() as $draft) {
            try {
                $label = $targets->label($targets->resolve($draft->target));
            } catch (Throwable) {
                // The page or section is gone: its draft is not worth showing.
                continue;
            }

            $drafts[] = [
                'target' => $draft->target,
                'label' => $label,
                'user' => $draft->user?->name,
                'updated_at' => $draft->updated_at?->toIso8601String(),
            ];
        }

        return $drafts;
    }

    /**
     * Pages and records that will appear on the site later.
     *
     * @return list<array{title: string, collection: string, record: int, publish_at: string}>
     */
    private function scheduled(Tenant $tenant): array
    {
        $now = Scheduling::now($tenant->id);
        $items = [];

        $collections = Collection::query()
            ->where(fn ($q) => $q->where('kind', PagesCollection::KIND)->orWhereNotNull('site_publish_at_field'))
            ->with('fields')
            ->get();

        foreach ($collections as $collection) {
            $field = $collection->field($collection->kind === PagesCollection::KIND ? Scheduling::PAGES_FIELD : (string) $collection->site_publish_at_field);
            $display = $collection->display_field ? $collection->field($collection->display_field) : null;
            $column = $field?->column();

            if (! $column) {
                continue;
            }

            $rows = DB::table($collection->table_name)
                ->where($column, '>', $now)
                ->orderBy($column)
                ->limit(5)
                ->get(array_filter(['id', $column, $display?->column()]));

            foreach ($rows as $row) {
                $values = (array) $row;
                $items[] = [
                    'title' => $display ? (string) ($values[(string) $display->column()] ?? '') : "#{$values['id']}",
                    'collection' => $collection->name,
                    'record' => (int) $values['id'],
                    'publish_at' => (string) $values[$column],
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp($a['publish_at'], $b['publish_at']));

        return array_slice($items, 0, 5);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        return array_values(Activity::query()->with('user')->latest('id')->limit(6)->get()->map(fn (Activity $a) => [
            'action' => $a->action,
            'subject' => $a->subject,
            'actor' => $a->actor ?? $a->user->name ?? 'Система',
            'created_at' => $a->created_at?->toIso8601String(),
        ])->all());
    }
}

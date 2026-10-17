<?php

namespace App\Support\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Visit statistics of company sites, without cookies or third-party counters.
 *
 * Views are added up per day and page. A visitor is recognized within one day by a salted
 * hash of IP and browser that lives only in the cache until the day ends, so the database
 * holds totals and nothing that identifies a person.
 */
class SiteStats
{
    public const KEEP_DAYS = 400;

    private const BOTS = '/bot|crawl|spider|slurp|preview|fetch|monitor|headless|lighthouse|curl|wget|python|http-client|java\//i';

    /**
     * Whether a served page counts as a visit.
     */
    public function counts(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        return $request->isMethod('GET')
            && $agent !== ''
            && ! preg_match(self::BOTS, $agent)
            // Browsers prefetching links the visitor may never open.
            && ! in_array(Str::lower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose'))), ['prefetch', 'prefetch;prerender'], true);
    }

    public function record(int $tenantId, Request $request): void
    {
        $date = now()->toDateString();
        $path = Str::limit('/'.ltrim(rawurldecode($request->getPathInfo()), '/'), 190, '');
        $visitor = hash_hmac('sha256', $tenantId.'|'.$request->ip().'|'.$request->userAgent(), (string) config('app.key').$date);
        $ttl = now()->endOfDay();

        // First view of the day on the site / on this page by this visitor.
        $newOnSite = Cache::add("visit:{$visitor}", true, $ttl);
        $newOnPage = Cache::add('visit:'.$visitor.':'.sha1($path), true, $ttl);

        $this->increment('site_visits', ['tenant_id' => $tenantId, 'date' => $date, 'path' => ''], $newOnSite);
        $this->increment('site_visits', ['tenant_id' => $tenantId, 'date' => $date, 'path' => $path], $newOnPage);

        $referrer = $this->referrer($request);

        if ($referrer) {
            DB::table('site_referrers')->upsert(
                ['tenant_id' => $tenantId, 'date' => $date, 'host' => $referrer, 'views' => 1],
                ['tenant_id', 'date', 'host'],
                ['views' => DB::raw('views + 1')],
            );
        }
    }

    /**
     * Totals per day, top pages and referrers for the last days.
     *
     * @return array{days: list<array{date: string, views: int, visitors: int}>, pages: list<array{path: string, views: int, visitors: int}>, referrers: list<array{host: string, views: int}>, totals: array{views: int, visitors: int}}
     */
    public function summary(int $tenantId, int $days): array
    {
        $from = now()->subDays($days - 1)->toDateString();

        $daily = DB::table('site_visits')
            ->where('tenant_id', $tenantId)
            ->where('path', '')
            ->where('date', '>=', $from)
            ->pluck('views', 'date')
            ->mapWithKeys(fn (mixed $views, mixed $date) => [Carbon::parse((string) $date)->toDateString() => (int) $views]);
        $dailyVisitors = DB::table('site_visits')
            ->where('tenant_id', $tenantId)
            ->where('path', '')
            ->where('date', '>=', $from)
            ->pluck('visitors', 'date')
            ->mapWithKeys(fn (mixed $visitors, mixed $date) => [Carbon::parse((string) $date)->toDateString() => (int) $visitors]);

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'views' => (int) ($daily[$date] ?? 0), 'visitors' => (int) ($dailyVisitors[$date] ?? 0)];
        }

        $pages = DB::table('site_visits')
            ->where('tenant_id', $tenantId)
            ->where('path', '!=', '')
            ->where('date', '>=', $from)
            ->groupBy('path')
            ->orderByDesc(DB::raw('SUM(views)'))
            ->orderBy('path')
            ->limit(10)
            ->get(['path', DB::raw('SUM(views) as views'), DB::raw('SUM(visitors) as visitors')])
            ->map(fn (object $row) => ['path' => (string) $row->path, 'views' => (int) $row->views, 'visitors' => (int) $row->visitors])
            ->all();

        $referrers = DB::table('site_referrers')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $from)
            ->groupBy('host')
            ->orderByDesc(DB::raw('SUM(views)'))
            ->orderBy('host')
            ->limit(10)
            ->get(['host', DB::raw('SUM(views) as views')])
            ->map(fn (object $row) => ['host' => (string) $row->host, 'views' => (int) $row->views])
            ->all();

        return [
            'days' => $series,
            'pages' => array_values($pages),
            'referrers' => array_values($referrers),
            'totals' => [
                'views' => array_sum(array_column($series, 'views')),
                'visitors' => array_sum(array_column($series, 'visitors')),
            ],
        ];
    }

    public function prune(): int
    {
        $before = now()->subDays(self::KEEP_DAYS)->toDateString();

        return DB::table('site_visits')->where('date', '<', $before)->delete()
            + DB::table('site_referrers')->where('date', '<', $before)->delete();
    }

    /**
     * @param  array{tenant_id: int, date: string, path: string}  $key
     */
    private function increment(string $table, array $key, bool $newVisitor): void
    {
        DB::table($table)->upsert(
            [...$key, 'views' => 1, 'visitors' => $newVisitor ? 1 : 0],
            ['tenant_id', 'date', 'path'],
            ['views' => DB::raw('views + 1'), 'visitors' => DB::raw('visitors + '.($newVisitor ? 1 : 0))],
        );
    }

    /**
     * The host of an outside page the visitor came from (links within the site are not referrers).
     */
    private function referrer(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = Str::lower((string) preg_replace('/^www\./i', '', $host));

        return $host === Str::lower((string) preg_replace('/^www\./i', '', $request->getHost())) ? null : Str::limit($host, 190, '');
    }
}

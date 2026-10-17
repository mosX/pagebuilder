<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Site\SitePresenter;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated JSON API of a company's site (published pages only),
 * e.g. for headless frontends. Browsers get server-rendered HTML from SitePageController.
 */
class SiteController extends Controller
{
    public function __construct(private SitePresenter $presenter) {}

    public function show(string $site): JsonResponse
    {
        return response()->json($this->site($site)[1]);
    }

    public function page(string $site, string $slug = PagesCollection::HOME_SLUG): JsonResponse
    {
        [$tenant] = $this->site($site);
        $page = $this->presenter->page($tenant, $slug);

        // Pages for signed-in visitors are not part of the anonymous API.
        abort_unless($page !== null && ! $page['members_only'], 404, 'Страница не найдена.');

        return response()->json($page);
    }

    /**
     * @return array{0: Tenant, 1: array<string, mixed>}
     */
    private function site(string $slug): array
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
        $info = $tenant ? $this->presenter->site($tenant, $tenant->siteUrl()) : null;

        abort_unless($tenant && $info, 404, 'Сайт не найден.');

        return [$tenant, $info];
    }
}

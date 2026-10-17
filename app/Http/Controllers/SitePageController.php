<?php

namespace App\Http\Controllers;

use App\Support\Collections\PagesCollection;
use App\Support\Site\CurrentSite;
use App\Support\Site\SitePresenter;
use App\Support\Site\SiteRenderer;
use App\Support\Site\SiteResolver;
use App\Support\Site\SiteSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves company sites as server-rendered HTML: on the company subdomain
 * ({slug}.{site_domain}) and, for previews, under /site/{slug} on the platform domain,
 * optionally prefixed with a language: /{locale}/{page}.
 */
class SitePageController extends Controller
{
    public function __construct(
        private SiteResolver $sites,
        private SitePresenter $presenter,
        private SiteRenderer $renderer,
    ) {}

    public function page(Request $request): Response|RedirectResponse
    {
        try {
            $site = $this->sites->resolve($request);
        } catch (NotFoundHttpException $e) {
            // "/{locale}/..." where the first segment is not a language of this site:
            // it is a page slug ("/it") or a section path ("/it/{record}").
            $first = $request->route('locale');

            if (! is_string($first)) {
                throw $e;
            }

            $site = $this->sites->resolve($request, ignoreLocale: true);
            $slug = $request->route('slug');

            return is_string($slug)
                ? $this->renderRecord($request, $site, $first, $slug)
                : $this->renderPage($request, $site, $first);
        }

        $slug = $request->route('slug');

        return $this->renderPage($request, $site, is_string($slug) ? $slug : PagesCollection::HOME_SLUG);
    }

    public function record(Request $request): Response
    {
        return $this->renderRecord($request, $this->sites->resolve($request), (string) $request->route('path'), (string) $request->route('record'));
    }

    public function search(Request $request, SiteSearch $search): Response
    {
        $site = $this->sites->resolve($request);
        $query = trim($request->string('q')->limit(100, '')->toString());

        return $this->renderer->render($site, [
            'title' => 'Поиск',
            'slug' => 'search',
            'description' => null,
            'blocks' => [[
                'id' => 'search',
                'type' => 'search_results',
                'props' => (object) [],
                'data' => [
                    'action' => $site->url('search'),
                    'query' => $query,
                    'min_length' => SiteSearch::MIN_LENGTH,
                    'results' => $search->search($site, $query),
                ],
            ]],
        ], 'search', indexable: false);
    }

    public function notFound(): never
    {
        abort(404);
    }

    private function renderPage(Request $request, CurrentSite $site, string $slug): Response|RedirectResponse
    {
        $page = $this->presenter->page($site->tenant, $slug, $site->baseUrl, $this->listPage($request), $site->locale());

        // Pages for signed-in visitors: guests are sent to sign in, or get a 404 when the site has no accounts.
        if ($page && $page['members_only'] && ! $site->member) {
            if (! $site->account['enabled']) {
                $page = null;
            } else {
                return redirect($site->absolute($site->url('account/login').'?redirect='.urlencode($request->getRequestUri())));
            }
        }

        return $this->renderer->render($site, $page, $slug === PagesCollection::HOME_SLUG ? '' : $slug);
    }

    private function renderRecord(Request $request, CurrentSite $site, string $path, string $record): Response
    {
        $page = $this->presenter->recordPage($site->tenant, $path, $record, $site->baseUrl, $this->listPage($request), $site->locale());

        return $this->renderer->render($site, $page, "{$path}/{$record}");
    }

    private function listPage(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }
}

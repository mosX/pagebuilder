<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Site\SitePresenter;
use App\Support\Site\SsrRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Serves company sites as server-rendered HTML: on the company subdomain
 * ({slug}.{site_domain}) and, for previews, under /site/{slug} on the platform domain.
 */
class SitePageController extends Controller
{
    public function __construct(
        private SitePresenter $presenter,
        private SsrRenderer $ssr,
    ) {}

    public function host(Request $request, string $site, ?string $slug = null): Response
    {
        return $this->render($request, $site, null, $slug, '', preview: false);
    }

    public function hostRecord(Request $request, string $site, string $path, string $record): Response
    {
        return $this->render($request, $site, $path, $record, '', preview: false);
    }

    public function preview(Request $request, string $site, ?string $slug = null): Response
    {
        return $this->render($request, $site, null, $slug, "/site/{$site}", preview: true);
    }

    public function previewRecord(Request $request, string $site, string $path, string $record): Response
    {
        return $this->render($request, $site, $path, $record, "/site/{$site}", preview: true);
    }

    public function notFound(): never
    {
        abort(404);
    }

    /**
     * @param  string|null  $path  Site path of a published collection, or null for a regular page.
     */
    private function render(Request $request, string $site, ?string $path, ?string $slug, string $baseUrl, bool $preview): Response
    {
        $tenant = Tenant::where('slug', $site)->where('is_active', true)->first();
        $info = $tenant ? $this->presenter->site($tenant, $baseUrl) : null;

        abort_unless($tenant && $info, 404);

        $slug = $slug ?: PagesCollection::HOME_SLUG;
        $listPage = max(1, $request->integer('page', 1));

        $page = $path === null
            ? $this->presenter->page($tenant, $slug, $baseUrl, $listPage)
            : $this->presenter->recordPage($tenant, $path, $slug, $baseUrl, $listPage);

        $payload = ['site' => $info, 'page' => $page];

        return response()->view('site', [
            'payload' => $payload,
            'html' => $this->ssr->render($payload),
            'meta' => $this->meta($tenant, $info['name'], $path === null ? $slug : "{$path}/{$slug}", $page, $preview),
        ], $page ? 200 : 404);
    }

    /**
     * @param  array{title: string, description: string|null, blocks: array<int, array<string, mixed>>}|null  $page
     * @return array{title: string, description: string, canonical: string, image: string|null, site_name: string, noindex: bool}
     */
    private function meta(Tenant $tenant, string $siteName, string $slug, ?array $page, bool $preview): array
    {
        $canonical = $tenant->siteUrl().$this->presenter->url('', $slug);
        $image = $page ? $this->firstImage($page['blocks']) : null;

        return [
            'title' => $page ? trim("{$page['title']} — {$siteName}", ' —') : "Страница не найдена — {$siteName}",
            'description' => Str::limit($page['description'] ?? $siteName, 300),
            'canonical' => $canonical,
            'image' => $image ? url($image) : null,
            'site_name' => $siteName,
            // Previews on the platform domain must not compete with the real site.
            'noindex' => $preview || ! $page,
        ];
    }

    /**
     * The first picture on the page, used as the social preview image.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function firstImage(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            $props = (array) ($block['props'] ?? []);
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $src = $props['image'] ?? $props['src'] ?? (($data['type'] ?? null) === 'image' ? ($data['value'] ?? null) : null);

            if (is_string($src) && $src !== '') {
                return $src;
            }

            $nested = is_array($block['children'] ?? null) ? $this->firstImage($block['children']) : null;

            if ($nested) {
                return $nested;
            }
        }

        return null;
    }
}

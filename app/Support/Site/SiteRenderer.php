<?php

namespace App\Support\Site;

use App\Support\Blocks\BlockDataResolver;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Turns a page of a company site into the HTML response: the SSR payload (site info,
 * page blocks, theme, header and footer), meta tags and language alternates.
 */
class SiteRenderer
{
    public function __construct(
        private SitePresenter $presenter,
        private SsrRenderer $ssr,
        private SiteDesign $design,
        private BlockDataResolver $resolver,
    ) {}

    /**
     * @param  array{title: string, slug: string, description: string|null, members_only?: bool, blocks: array<int, array<string, mixed>>}|null  $page
     * @param  string  $path  The page's path relative to the site's base URL ('' for the home page).
     * @param  bool  $indexable  Search engines skip service pages (search, account).
     */
    public function render(CurrentSite $site, ?array $page, string $path, bool $indexable = true): Response
    {
        $info = $this->presenter->site($site->tenant, $site->baseUrl, $site->locale());

        abort_unless($info !== null, 404);

        $context = $site->context();
        $design = $this->design->present($site->settings);

        $payload = [
            'site' => [
                ...$info,
                'root_url' => $site->rootUrl,
                'locale' => $site->localeCode,
                'languages' => $this->languages($site, $path),
                'search_url' => $site->url('search'),
                'account' => $site->account['enabled'] ? [
                    'url' => $site->url('account'),
                    'login_url' => $site->url('account/login'),
                    'register_url' => $site->account['registration'] ? $site->url('account/register') : null,
                    'member' => $site->member ? ['name' => $site->member->name, 'email' => $site->member->email] : null,
                ] : null,
            ],
            'page' => $page,
            'theme' => $design['vars'],
            'layout' => [
                'header' => $this->resolver->resolveTree($context, $site->settings->header ?? []),
                'footer' => $this->resolver->resolveTree($context, $site->settings->footer ?? []),
            ],
        ];

        return response()->view('site', [
            'payload' => $payload,
            'html' => $this->ssr->render($payload),
            'design' => $design,
            'meta' => $this->meta($site, $info['name'], $path, $page, $indexable),
        ], $page ? 200 : 404);
    }

    /**
     * The page in every language of the site, for the language switcher.
     *
     * @return list<array{code: string, label: string, url: string, active: bool}>
     */
    private function languages(CurrentSite $site, string $path): array
    {
        $codes = [$site->languages['default'], ...$site->languages['extra']];

        return count($codes) < 2 ? [] : array_map(fn (string $code) => [
            'code' => $code,
            'label' => SiteLanguages::LOCALES[$code],
            'url' => $site->urlIn($code, $path),
            'active' => $code === $site->localeCode,
        ], $codes);
    }

    /**
     * @param  array{title: string, description: string|null, blocks: array<int, array<string, mixed>>}|null  $page
     * @return array<string, mixed>
     */
    private function meta(CurrentSite $site, string $siteName, string $path, ?array $page, bool $indexable): array
    {
        $image = $page ? $this->firstImage($page['blocks']) : null;
        $codes = [$site->languages['default'], ...$site->languages['extra']];
        $indexed = $page && $indexable && ! $site->preview;

        return [
            'title' => $page ? trim("{$page['title']} — {$siteName}", ' —') : "Страница не найдена — {$siteName}",
            'description' => Str::limit($page['description'] ?? $siteName, 300),
            'canonical' => $site->urlIn($site->localeCode, $path, absolute: true),
            'image' => $image ? url($image) : null,
            'site_name' => $siteName,
            'lang' => $site->localeCode,
            // Translations of the page, so search engines show visitors their language.
            'alternates' => $indexed && count($codes) > 1
                ? array_map(fn (string $code) => ['lang' => $code, 'url' => $site->urlIn($code, $path, absolute: true)], $codes)
                : [],
            // Previews on the platform domain must not compete with the real site.
            'noindex' => ! $indexed,
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

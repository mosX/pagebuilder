<?php

namespace App\Support\Site;

use App\Models\SiteSettings;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;
use Illuminate\Support\Str;

/**
 * Theme, menu, header and footer of a company site.
 *
 * Settings are created with sensible defaults on first use. Everything users send is
 * normalized here: colors must be hex, fonts come from an allow-list (all with Cyrillic),
 * menu links are either page slugs or safe URLs.
 */
class SiteDesign
{
    /** Font key => [family, Bunny Fonts slug]. All support Cyrillic. */
    public const FONTS = [
        'inter' => ['Inter', 'inter'],
        'roboto' => ['Roboto', 'roboto'],
        'open-sans' => ['Open Sans', 'open-sans'],
        'montserrat' => ['Montserrat', 'montserrat'],
        'manrope' => ['Manrope', 'manrope'],
        'nunito' => ['Nunito', 'nunito'],
        'rubik' => ['Rubik', 'rubik'],
        'raleway' => ['Raleway', 'raleway'],
        'pt-sans' => ['PT Sans', 'pt-sans'],
        'pt-serif' => ['PT Serif', 'pt-serif'],
        'lora' => ['Lora', 'lora'],
        'merriweather' => ['Merriweather', 'merriweather'],
        'playfair-display' => ['Playfair Display', 'playfair-display'],
        'oswald' => ['Oswald', 'oswald'],
        'unbounded' => ['Unbounded', 'unbounded'],
    ];

    public const RADII = ['none' => '0', 'small' => '0.25rem', 'medium' => '0.5rem', 'large' => '1rem'];

    public const DEFAULT_THEME = [
        'primary' => '#2563eb',
        'text' => '#111827',
        'background' => '#ffffff',
        'heading_font' => 'inter',
        'body_font' => 'inter',
        'radius' => 'medium',
    ];

    public const MAX_MENU_ITEMS = 12;

    public function __construct(private BlockRegistry $blocks) {}

    public function for(Tenant $tenant): SiteSettings
    {
        $settings = SiteSettings::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->first();

        if ($settings) {
            return $settings;
        }

        $settings = new SiteSettings;
        $settings->forceFill([
            'tenant_id' => $tenant->id,
            'theme' => self::DEFAULT_THEME,
            'menu' => [],
            'header' => $this->blocks->sanitize($this->defaultHeader(), 'header'),
            'footer' => $this->blocks->sanitize($this->defaultFooter(), 'footer'),
        ])->save();

        return $settings;
    }

    /**
     * @return array{primary: string, text: string, background: string, heading_font: string, body_font: string, radius: string}
     */
    public function theme(mixed $theme): array
    {
        $theme = is_array($theme) ? $theme : [];
        $color = fn (string $key) => is_string($theme[$key] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $theme[$key])
            ? strtolower($theme[$key])
            : self::DEFAULT_THEME[$key];
        $font = fn (string $key) => array_key_exists((string) ($theme[$key] ?? ''), self::FONTS) ? (string) $theme[$key] : self::DEFAULT_THEME[$key];

        return [
            'primary' => $color('primary'),
            'text' => $color('text'),
            'background' => $color('background'),
            'heading_font' => $font('heading_font'),
            'body_font' => $font('body_font'),
            'radius' => array_key_exists((string) ($theme['radius'] ?? ''), self::RADII) ? (string) $theme['radius'] : self::DEFAULT_THEME['radius'],
        ];
    }

    /**
     * Menu items: {label, labels: {locale: label}, type: page|url, page, url, children[]} (one nesting level).
     *
     * @return list<array<string, mixed>>
     */
    public function menu(mixed $menu, bool $nested = true): array
    {
        $items = [];

        foreach (array_slice(is_array($menu) ? array_values(array_filter($menu, 'is_array')) : [], 0, self::MAX_MENU_ITEMS) as $item) {
            $type = ($item['type'] ?? 'page') === 'url' ? 'url' : 'page';
            $label = Str::limit(trim(is_string($item['label'] ?? null) ? $item['label'] : ''), 60, '');

            if ($label === '') {
                continue;
            }

            $labels = [];

            foreach (is_array($item['labels'] ?? null) ? $item['labels'] : [] as $locale => $translated) {
                $translated = Str::limit(trim(is_string($translated) ? $translated : ''), 60, '');

                if (array_key_exists((string) $locale, SiteLanguages::LOCALES) && $translated !== '') {
                    $labels[(string) $locale] = $translated;
                }
            }

            $items[] = [
                'label' => $label,
                // Labels in the site's other languages; the main label is the fallback.
                'labels' => (object) $labels,
                'type' => $type,
                'page' => $type === 'page' && is_string($item['page'] ?? null) && preg_match('/^[a-z0-9-]{1,100}$/', $item['page']) ? $item['page'] : '',
                'url' => $type === 'url' && is_string($item['url'] ?? null) ? $this->blocks->safeUrl($item['url']) : '',
                'children' => $nested ? $this->menu($item['children'] ?? [], nested: false) : [],
            ];
        }

        return $items;
    }

    /**
     * Theme values for the site: CSS variables, font stylesheet and favicon.
     *
     * @return array<string, mixed>
     */
    public function present(SiteSettings $settings): array
    {
        $theme = $this->theme($settings->theme);
        [$headingFamily, $headingSlug] = self::FONTS[$theme['heading_font']];
        [$bodyFamily, $bodySlug] = self::FONTS[$theme['body_font']];

        return [
            'vars' => [
                '--site-primary' => $theme['primary'],
                '--site-primary-contrast' => $this->contrast($theme['primary']),
                '--site-text' => $theme['text'],
                '--site-background' => $theme['background'],
                '--site-radius' => self::RADII[$theme['radius']],
                '--site-font-heading' => "'{$headingFamily}', ui-sans-serif, system-ui, sans-serif",
                '--site-font-body' => "'{$bodyFamily}', ui-sans-serif, system-ui, sans-serif",
            ],
            'fonts_url' => 'https://fonts.bunny.net/css?family='.implode('|', array_unique([
                "{$headingSlug}:400,600,700",
                "{$bodySlug}:400,500,600,700",
            ])).'&display=swap',
            'logo' => $settings->logo,
            'favicon' => $settings->favicon,
        ];
    }

    /**
     * Menu with final URLs for a site rendered at $baseUrl. Until a menu is set up,
     * the published pages are listed, so new sites have navigation right away.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveMenu(Tenant $tenant, SiteSettings $settings, string $baseUrl, ?string $locale = null): array
    {
        $presenter = app(SitePresenter::class);
        $menu = $this->menu($settings->menu);

        if ($menu === []) {
            return array_map(
                fn (array $page) => ['label' => $page['title'], 'url' => $page['url'], 'slug' => $page['slug'], 'children' => []],
                $presenter->site($tenant, $baseUrl, $locale)['pages'] ?? [],
            );
        }

        $resolve = function (array $item) use ($baseUrl, $presenter, $locale): array {
            $isPage = $item['type'] === 'page' && $item['page'] !== '';
            $labels = (array) $item['labels'];

            return [
                'label' => $locale && is_string($labels[$locale] ?? null) ? $labels[$locale] : $item['label'],
                'url' => $isPage ? $presenter->url($baseUrl, $item['page']) : ($item['url'] ?: '#'),
                'slug' => $isPage ? $item['page'] : null,
                'children' => [],
            ];
        };

        return array_map(function (array $item) use ($resolve) {
            $resolved = $resolve($item);
            $resolved['children'] = array_map($resolve, $item['children'] ?? []);

            return $resolved;
        }, $menu);
    }

    /**
     * Black or white text, whichever reads better on the color.
     */
    private function contrast(string $hex): string
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $luminance = (0.299 * (int) $r + 0.587 * (int) $g + 0.114 * (int) $b) / 255;

        return $luminance > 0.6 ? '#111827' : '#ffffff';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultHeader(): array
    {
        return [[
            'type' => 'section',
            'props' => ['padding' => 'small', 'width' => 'wide'],
            'children' => [[
                'type' => 'row',
                'props' => ['justify' => 'between'],
                'children' => [
                    ['type' => 'site_logo', 'props' => []],
                    ['type' => 'site_menu', 'props' => []],
                ],
            ]],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultFooter(): array
    {
        return [[
            'type' => 'section',
            'props' => ['padding' => 'medium', 'width' => 'wide', 'background' => '#f9fafb'],
            'children' => [[
                'type' => 'row',
                'props' => ['justify' => 'between'],
                'children' => [
                    ['type' => 'site_logo', 'props' => ['size' => 'small']],
                    ['type' => 'site_menu', 'props' => ['variant' => 'muted']],
                ],
            ]],
        ]];
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\Site\SiteResolver;
use App\Support\Site\SiteSitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * sitemap.xml and robots.txt of company sites, and robots.txt of the platform itself.
 */
class SiteMapController extends Controller
{
    public function sitemap(Request $request, SiteResolver $sites, SiteSitemap $sitemap): Response
    {
        $site = $sites->resolve($request);

        return response($sitemap->xml($site->tenant), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Sites are open to search engines except service pages; the sitemap is announced here.
     */
    public function robots(Request $request, SiteResolver $sites): Response
    {
        $site = $sites->resolve($request);

        return $this->text(implode("\n", [
            'User-agent: *',
            'Disallow: /account',
            'Disallow: /search',
            'Disallow: /*/account',
            'Disallow: /*/search',
            '',
            'Sitemap: '.$site->tenant->siteUrl().'/sitemap.xml',
        ]));
    }

    /**
     * The admin and the platform domain are never indexed.
     */
    public function platformRobots(): Response
    {
        return $this->text("User-agent: *\nDisallow: /");
    }

    private function text(string $content): Response
    {
        return response($content."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

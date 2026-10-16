<?php

namespace App\Support\Site;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Asks the Node SSR server (bootstrap/ssr/site-ssr.js) to render a page to HTML.
 *
 * Rendering is an enhancement: when the server is down or slow, null is returned and the
 * page renders in the browser. A failed connection is remembered briefly so visitors do
 * not wait for the timeout on every request.
 */
class SsrRenderer
{
    private const DOWN_KEY = 'site-ssr:down';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload): ?string
    {
        $url = config('tenancy.ssr.url');

        if (! config('tenancy.ssr.enabled') || ! is_string($url) || Cache::get(self::DOWN_KEY)) {
            return null;
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout((int) config('tenancy.ssr.timeout', 2))
                ->post($url, $payload);
        } catch (ConnectionException) {
            Cache::put(self::DOWN_KEY, true, 30);

            return null;
        }

        $html = $response->successful() ? $response->json('html') : null;

        return is_string($html) ? $html : null;
    }
}

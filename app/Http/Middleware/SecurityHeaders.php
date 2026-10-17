<?php

namespace App\Http\Middleware;

use App\Support\Site\Captcha;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for the admin and company sites.
 *
 * The Content Security Policy allows scripts from this app only: neither page has inline
 * scripts (site data is a JSON block), so injected markup cannot run code even if it got
 * past sanitizing. Images and media may come from any https host (blocks link them),
 * frames only from the video players the video block embeds. While the Vite dev server
 * runs, the policy is left out (its client is served from another origin).
 */
class SecurityHeaders
{
    public const FRAME_SOURCES = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (str_contains((string) $headers->get('Content-Type'), 'text/html') && ! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    private function policy(): string
    {
        $captcha = implode('', array_map(fn (string $origin) => ' '.$origin, app(Captcha::class)->cspOrigins()));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'{$captcha}",
            // Vue binds inline style attributes (theme variables, colors of blocks).
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' https: data: blob:",
            "media-src 'self' https:",
            'frame-src '.implode(' ', self::FRAME_SOURCES).$captcha,
            "connect-src 'self'{$captcha}",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}

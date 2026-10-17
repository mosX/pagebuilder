<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Captcha of site forms
    |--------------------------------------------------------------------------
    |
    | "yandex" (Yandex SmartCaptcha), "turnstile" (Cloudflare Turnstile) or "none".
    | Keys come from the provider's console. Without a provider forms are protected
    | by the honeypot and rate limits only.
    |
    */

    'provider' => env('CAPTCHA_PROVIDER', 'none'),

    'site_key' => env('CAPTCHA_SITE_KEY'),

    'secret' => env('CAPTCHA_SECRET'),

];

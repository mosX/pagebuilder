<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform super admin
    |--------------------------------------------------------------------------
    |
    | Used by SuperAdminSeeder to create the first platform administrator
    | and the company they start working in.
    |
    */

    'super_admin' => [
        'name' => env('SUPERADMIN_NAME', 'Super Admin'),
        'email' => env('SUPERADMIN_EMAIL', 'admin@pagebuilder.test'),
        'password' => env('SUPERADMIN_PASSWORD', 'password'),
        'company' => env('SUPERADMIN_COMPANY', 'Main Company'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Company sites
    |--------------------------------------------------------------------------
    |
    | Every company's public site lives on a subdomain: {slug}.{site_domain}.
    | The web server must route *.{site_domain} to this app (OSPanel: aliases).
    | Reserved names can never become a company slug.
    |
    */

    'site_domain' => env('TENANT_SITE_DOMAIN', 'pagebuilder.test'),

    'site_scheme' => env('TENANT_SITE_SCHEME', 'https'),

    /*
    | The same sites under /site/{slug} on the platform domain, for development without
    | wildcard DNS. Off in production: company content must not run on the admin's origin
    | (it shares the admin session), so the path redirects to the real site address.
    */
    'preview_path' => (bool) env('SITE_PREVIEW_PATH', env('APP_ENV', 'production') !== 'production'),

    /*
    | Companies one user may own. Every company has real tables, so creation is capped
    | (platform super admins are not limited).
    */
    'max_owned_companies' => (int) env('MAX_COMPANIES_PER_USER', 10),

    'reserved_subdomains' => [
        'www', 'api', 'app', 'admin', 'dashboard', 'panel', 'mail', 'smtp', 'imap', 'pop',
        'ftp', 'ns1', 'ns2', 'cdn', 'static', 'assets', 'media', 'storage', 'files', 'img',
        'help', 'support', 'docs', 'status', 'blog', 'dev', 'test', 'staging', 'site', 'sites',
        'login', 'register', 'auth', 'account', 'billing', 'platform',
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-side rendering of company sites
    |--------------------------------------------------------------------------
    |
    | Pages are rendered to HTML by a small Node server (node bootstrap/ssr/site-ssr.js).
    | When it is not running, pages are still served with meta tags and render in the browser.
    |
    */

    'ssr' => [
        'enabled' => (bool) env('SITE_SSR_ENABLED', true),
        'url' => env('SITE_SSR_URL', 'http://127.0.0.1:13714/render'),
        'timeout' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Page cache of company sites
    |--------------------------------------------------------------------------
    |
    | Pages are cached for guests and dropped whenever the company changes something.
    | The TTL (seconds) only limits how long an entry may live in any case.
    |
    */

    'page_cache' => [
        'enabled' => (bool) env('SITE_PAGE_CACHE', true),
        'ttl' => (int) env('SITE_PAGE_CACHE_TTL', 3600),
    ],

];

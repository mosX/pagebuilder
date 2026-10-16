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

];

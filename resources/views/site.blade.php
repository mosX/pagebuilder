<!DOCTYPE html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $meta['title'] }}</title>
        <meta name="description" content="{{ $meta['description'] }}">
        <link rel="canonical" href="{{ $meta['canonical'] }}">
        @if ($meta['noindex'])
            <meta name="robots" content="noindex">
        @endif

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $meta['site_name'] }}">
        <meta property="og:title" content="{{ $meta['title'] }}">
        <meta property="og:description" content="{{ $meta['description'] }}">
        <meta property="og:url" content="{{ $meta['canonical'] }}">
        @if ($meta['image'])
            <meta property="og:image" content="{{ $meta['image'] }}">
        @endif

        @fonts

        @vite(['resources/css/app.css', 'resources/js/site.ts'])
    </head>
    <body class="font-sans antialiased">
        {{-- Server-rendered by the Node SSR server when it runs; otherwise rendered in the browser. --}}
        <div id="site" data-ssr="{{ $html ? '1' : '0' }}">{!! $html !!}</div>
        <script>window.__SITE__ = {{ Js::from($payload) }};</script>
    </body>
</html>

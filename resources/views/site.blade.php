<!DOCTYPE html>
<html lang="{{ $meta['lang'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $meta['title'] }}</title>
        <meta name="description" content="{{ $meta['description'] }}">
        <link rel="canonical" href="{{ $meta['canonical'] }}">
        @foreach ($meta['alternates'] as $alternate)
            <link rel="alternate" hreflang="{{ $alternate['lang'] }}" href="{{ $alternate['url'] }}">
        @endforeach
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

        @if ($design['favicon'])
            <link rel="icon" href="{{ $design['favicon'] }}">
        @endif
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="{{ $design['fonts_url'] }}">

        @vite(['resources/css/app.css', 'resources/js/site.ts'])
    </head>
    {{-- Theme variables on <body> too, so the page is styled before JavaScript runs. --}}
    <body class="font-body antialiased" style="{{ collect($design['vars'])->map(fn ($value, $name) => $name.': '.$value)->implode('; ') }}; background: var(--site-background); color: var(--site-text)">
        {{-- Server-rendered by the Node SSR server when it runs; otherwise rendered in the browser. --}}
        <div id="site" data-ssr="{{ $html ? '1' : '0' }}">{!! $html !!}</div>
        <script type="application/json" id="site-payload">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    </body>
</html>

@php($saved = request()->cookie('radiusone_theme') ?? request()->cookie('frweb_theme'))
@php($theme = in_array($saved, ['light', 'dark'], true) ? $saved : null)
<!doctype html>
<html lang="en" @if($theme) data-theme="{{ $theme }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
        <link rel="icon" href="/favicon.ico?v=3" sizes="16x16 32x32 48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
        <link rel="manifest" href="/manifest.json?v=3">
        <meta name="theme-color" content="#0d7f78">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ \App\Support\Branding::name() }}</title>
        </x-inertia::head>
    </head>
    <body class="app-shell">
        <x-inertia::app />
    </body>
</html>

@php($theme = in_array(request()->cookie('frweb_theme'), ['light', 'dark'], true) ? request()->cookie('frweb_theme') : null)
<!doctype html>
<html lang="en" @if($theme) data-theme="{{ $theme }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="shortcut icon" href="/favicon.ico">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="app-shell">
        <x-inertia::app />
    </body>
</html>

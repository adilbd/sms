<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin | {{ config('seo.site_name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite('resources/js/admin/main.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>

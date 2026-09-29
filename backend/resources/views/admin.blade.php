<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin | {{ $institute['name_en'] }}</title>
    <link rel="icon" href="{{ $institute['favicon_url'] ?? asset('favicon.ico') }}">
    @vite('resources/js/admin/main.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>

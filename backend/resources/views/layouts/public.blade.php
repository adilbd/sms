<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563eb">

    @yield('seo')

    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="alternate" type="application/xml" title="Sitemap" href="{{ route('sitemap') }}">
    @vite('resources/css/public.css')
</head>
<body class="min-h-screen flex flex-col bg-white text-gray-800 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:px-4 focus:py-2 focus:rounded">Skip to content</a>

    <header class="border-b border-gray-100 bg-white">
        <div class="container-page flex items-center justify-between h-16">
            <a href="{{ route('home') }}" class="text-lg font-bold text-primary-700">{{ config('seo.site_name') }}</a>

            <nav aria-label="Main">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm font-medium">
                    @foreach ([
                        'home' => 'Home',
                        'about' => 'About',
                        'admissions' => 'Admissions',
                        'news.index' => 'News',
                        'events.index' => 'Events',
                        'contact' => 'Contact',
                    ] as $routeName => $label)
                        @php($active = request()->routeIs($routeName) || request()->routeIs(str_replace('.index', '.*', $routeName)))
                        <li>
                            <a href="{{ route($routeName) }}"
                               class="{{ $active ? 'text-primary-700' : 'text-gray-600 hover:text-primary-700' }}"
                               @if ($active) aria-current="page" @endif>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    <footer class="border-t border-gray-100 bg-gray-50 text-sm text-gray-600">
        @php($org = config('seo.organization'))
        <div class="container-page py-10 grid gap-8 sm:grid-cols-3">
            <div>
                <p class="font-semibold text-gray-900">{{ config('seo.site_name') }}</p>
                <address class="not-italic mt-2 leading-relaxed">
                    {{ $org['street'] }}<br>
                    {{ $org['city'] }}{{ $org['region'] ? ', '.$org['region'] : '' }} {{ $org['postal_code'] }}
                </address>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Contact</p>
                <p class="mt-2"><a class="hover:text-primary-700" href="mailto:{{ $org['email'] }}">{{ $org['email'] }}</a></p>
                <p><a class="hover:text-primary-700" href="tel:{{ preg_replace('/[^\d+]/', '', $org['phone']) }}">{{ $org['phone'] }}</a></p>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Explore</p>
                <ul class="mt-2 space-y-1">
                    <li><a class="hover:text-primary-700" href="{{ route('admissions') }}">Admissions</a></li>
                    <li><a class="hover:text-primary-700" href="{{ route('news.index') }}">News</a></li>
                    <li><a class="hover:text-primary-700" href="{{ route('events.index') }}">Events</a></li>
                    <li><a class="hover:text-primary-700" href="{{ url('/admin') }}" rel="nofollow">Staff &amp; parent login</a></li>
                </ul>
            </div>
        </div>
        <p class="container-page pb-8 text-xs text-gray-500">&copy; {{ now()->year }} {{ config('seo.site_name') }}. All rights reserved.</p>
    </footer>
</body>
</html>

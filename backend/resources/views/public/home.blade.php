@extends('layouts.public')

@section('seo')
    <x-seo :json-ld="$jsonLd" />
@endsection

@section('content')
    <section class="bg-gradient-to-b from-primary-50 to-white">
        <div class="container-page py-20 text-center">
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-gray-900">
                Welcome to {{ config('seo.site_name') }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg text-gray-600">
                A caring, future-focused school where every student is known, challenged and supported
                to reach their full potential in academics, arts and sports.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('admissions') }}" class="btn-public">Apply for admission</a>
                <a href="{{ route('contact') }}" class="btn-public-outline">Book a visit</a>
            </div>
        </div>
    </section>

    <section class="container-page py-16" aria-labelledby="why-heading">
        <h2 id="why-heading" class="text-2xl font-bold text-gray-900">Why families choose us</h2>
        <div class="mt-8 grid gap-6 sm:grid-cols-3">
            @foreach ([
                ['Strong academics', 'A broad, rigorous curriculum with small class sizes and regular progress reporting to parents.'],
                ['Whole-child development', 'Sports, music, drama, clubs and community service help students grow in confidence.'],
                ['Connected community', 'Parents follow attendance, exams and fees online and stay in touch with teachers.'],
            ] as [$heading, $text])
                <div class="card-public p-6">
                    <h3 class="font-semibold text-gray-900">{{ $heading }}</h3>
                    <p class="mt-2 text-sm text-gray-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($latestNews->isNotEmpty())
        <section class="bg-gray-50" aria-labelledby="news-heading">
            <div class="container-page py-16">
                <div class="flex items-end justify-between gap-4">
                    <h2 id="news-heading" class="text-2xl font-bold text-gray-900">Latest news</h2>
                    <a href="{{ route('news.index') }}" class="text-sm font-medium text-primary-700 hover:underline">All news →</a>
                </div>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($latestNews as $post)
                        <x-post-card :post="$post" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($upcomingEvents->isNotEmpty())
        <section class="container-page py-16" aria-labelledby="events-heading">
            <div class="flex items-end justify-between gap-4">
                <h2 id="events-heading" class="text-2xl font-bold text-gray-900">Upcoming events</h2>
                <a href="{{ route('events.index') }}" class="text-sm font-medium text-primary-700 hover:underline">All events →</a>
            </div>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($upcomingEvents as $post)
                    <x-post-card :post="$post" />
                @endforeach
            </div>
        </section>
    @endif
@endsection

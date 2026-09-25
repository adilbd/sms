@extends('layouts.public')

@php
    $pageTitle = $section['title'].($posts->currentPage() > 1 ? ' – Page '.$posts->currentPage() : '');
@endphp

@section('seo')
    <x-seo :title="$pageTitle" :description="$section['description']" :json-ld="$jsonLd" />
    @if ($posts->previousPageUrl())
        <link rel="prev" href="{{ $posts->currentPage() === 2 ? route($section['route']) : $posts->previousPageUrl() }}">
    @endif
    @if ($posts->nextPageUrl())
        <link rel="next" href="{{ $posts->nextPageUrl() }}">
    @endif
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), $section['title'] => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">{{ $section['title'] }}</h1>
        <p class="mt-3 max-w-2xl text-gray-600">{{ $section['description'] }}</p>

        @if ($posts->isEmpty())
            <p class="mt-10 text-gray-500">Nothing here yet. Please check back soon.</p>
        @else
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <x-post-card :post="$post" heading-level="h2" />
                @endforeach
            </div>

            @if ($posts->hasPages())
                <nav class="mt-10 flex items-center justify-between" aria-label="Pagination">
                    @if ($posts->previousPageUrl())
                        <a class="btn-public-outline" rel="prev"
                           href="{{ $posts->currentPage() === 2 ? route($section['route']) : $posts->previousPageUrl() }}">← Newer</a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-sm text-gray-500">Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>
                    @if ($posts->nextPageUrl())
                        <a class="btn-public-outline" rel="next" href="{{ $posts->nextPageUrl() }}">Older →</a>
                    @else
                        <span></span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection

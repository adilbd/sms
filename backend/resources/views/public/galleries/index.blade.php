@extends('layouts.public')

@php
    $pageTitle = 'Gallery'.($galleries->currentPage() > 1 ? ' – Page '.$galleries->currentPage() : '');
    $description = 'Photos and videos from our school events, campus and activities.';
@endphp

@section('seo')
    <x-seo :title="$pageTitle" :description="$description" :json-ld="$jsonLd" />
    @if ($galleries->previousPageUrl())
        <link rel="prev" href="{{ $galleries->currentPage() === 2 ? route('gallery.index') : $galleries->previousPageUrl() }}">
    @endif
    @if ($galleries->nextPageUrl())
        <link rel="next" href="{{ $galleries->nextPageUrl() }}">
    @endif
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Gallery' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">Gallery</h1>
        <p class="mt-3 max-w-2xl text-gray-600">{{ $description }}</p>

        @if ($galleries->isEmpty())
            <p class="mt-10 text-gray-500">Nothing here yet. Please check back soon.</p>
        @else
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($galleries as $gallery)
                    <x-gallery-card :gallery="$gallery" />
                @endforeach
            </div>

            @if ($galleries->hasPages())
                <nav class="mt-10 flex items-center justify-between" aria-label="Pagination">
                    @if ($galleries->previousPageUrl())
                        <a class="btn-public-outline" rel="prev"
                           href="{{ $galleries->currentPage() === 2 ? route('gallery.index') : $galleries->previousPageUrl() }}">← Newer</a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-sm text-gray-500">Page {{ $galleries->currentPage() }} of {{ $galleries->lastPage() }}</span>
                    @if ($galleries->nextPageUrl())
                        <a class="btn-public-outline" rel="next" href="{{ $galleries->nextPageUrl() }}">Older →</a>
                    @else
                        <span></span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection

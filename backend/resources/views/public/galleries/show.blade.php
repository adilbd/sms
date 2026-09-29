@extends('layouts.public')

@section('seo')
    <x-seo :title="$gallery->seoTitle()"
           :description="$gallery->seoDescription()"
           type="website"
           :modified-time="$gallery->updated_at?->toIso8601String()"
           :json-ld="$jsonLd" />
@endsection

@php
    $images = $gallery->items->where('type', \App\Models\GalleryItem::TYPE_IMAGE);
    $videos = $gallery->items->where('type', \App\Models\GalleryItem::TYPE_VIDEO);
@endphp

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Gallery' => route('gallery.index'), $gallery->title => null]" />

        <header class="mt-6 max-w-3xl">
            <h1 class="text-4xl font-bold text-gray-900">{{ $gallery->title }}</h1>
            @if ($gallery->description)
                <p class="mt-3 text-gray-600">{{ $gallery->description }}</p>
            @endif
        </header>

        @if ($gallery->items->isEmpty())
            <p class="mt-10 text-gray-500">No photos or videos have been added to this gallery yet.</p>
        @else
            @if ($images->isNotEmpty())
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($images as $item)
                        <a href="#photo-{{ $item->id }}" class="block aspect-square overflow-hidden rounded-xl bg-gray-100">
                            <img src="{{ $item->thumbnailUrl() }}" alt="{{ $item->caption ?: $gallery->title }}" loading="lazy" class="h-full w-full object-cover transition-transform hover:scale-105">
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($videos->isNotEmpty())
                <div class="mt-10 grid gap-6 sm:grid-cols-2">
                    @foreach ($videos as $item)
                        <figure>
                            <div class="aspect-video overflow-hidden rounded-xl">
                                <iframe
                                    src="{{ $item->embedUrl() }}"
                                    title="{{ $item->caption ?: $gallery->title }}"
                                    loading="lazy"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                    class="h-full w-full"
                                ></iframe>
                            </div>
                            @if ($item->caption)
                                <figcaption class="mt-2 text-sm text-gray-500">{{ $item->caption }}</figcaption>
                            @endif
                        </figure>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    {{-- A lightweight, CSS-only lightbox: each thumbnail links to #photo-{id}, and the
         matching overlay below only becomes visible via the :target selector (see
         .gallery-lightbox in resources/css/public.css). No JavaScript needed. --}}
    @foreach ($images as $item)
        <div id="photo-{{ $item->id }}" class="gallery-lightbox" role="dialog" aria-modal="true" aria-label="{{ $item->caption ?: $gallery->title }}">
            <a href="#" class="gallery-lightbox-backdrop" aria-label="Close"></a>
            <figure class="gallery-lightbox-content">
                <img src="{{ $item->thumbnailUrl() }}" alt="{{ $item->caption ?: $gallery->title }}" class="gallery-lightbox-image">
                @if ($item->caption)
                    <figcaption class="mt-2 text-center text-sm text-white">{{ $item->caption }}</figcaption>
                @endif
            </figure>
            <a href="#" class="gallery-lightbox-close" aria-label="Close">&times;</a>
        </div>
    @endforeach
@endsection

@props(['gallery'])
<article class="card-public flex flex-col overflow-hidden">
    <a href="{{ $gallery->url() }}" class="block aspect-video w-full overflow-hidden bg-gray-100">
        @if ($gallery->coverImageUrl())
            <img src="{{ $gallery->coverImageUrl() }}" alt="" loading="lazy" width="640" height="360" class="h-full w-full object-cover">
        @else
            <span class="flex h-full w-full items-center justify-center text-4xl text-gray-300" aria-hidden="true">🖼️</span>
        @endif
    </a>
    <div class="p-5 flex flex-col flex-1">
        <h2 class="text-lg font-semibold text-gray-900">
            <a href="{{ $gallery->url() }}" class="hover:text-primary-700">{{ $gallery->title }}</a>
        </h2>
        <p class="mt-2 text-sm text-gray-500">
            {{ $gallery->items_count }} {{ Str::plural('item', $gallery->items_count) }}
        </p>
        @if ($gallery->description)
            <p class="mt-2 text-gray-600 text-sm flex-1">{{ Str::limit($gallery->description, 120) }}</p>
        @endif
        <a href="{{ $gallery->url() }}" class="mt-4 text-sm font-medium text-primary-700 hover:underline">
            View gallery<span class="sr-only"> {{ $gallery->title }}</span> →
        </a>
    </div>
</article>

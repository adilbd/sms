@props(['post', 'headingLevel' => 'h3'])
<article class="card-public flex flex-col">
    @if ($post->coverImageUrl())
        <img src="{{ $post->coverImageUrl() }}" alt="" loading="lazy" width="640" height="360" class="aspect-video w-full object-cover rounded-t-xl">
    @endif
    <div class="p-5 flex flex-col flex-1">
        @if ($post->type === \App\Models\Post::TYPE_EVENT && $post->event_starts_at)
            <p class="text-xs font-semibold uppercase tracking-wide text-primary-700">
                <time datetime="{{ $post->event_starts_at->toIso8601String() }}">{{ $post->event_starts_at->format('D, M j, Y · g:i A') }}</time>
                @if ($post->location) · {{ $post->location }} @endif
            </p>
        @elseif ($post->published_at)
            <p class="text-xs text-gray-500">
                <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->format('M j, Y') }}</time>
            </p>
        @endif
        <{{ $headingLevel }} class="mt-2 text-lg font-semibold text-gray-900">
            <a href="{{ $post->url() }}" class="hover:text-primary-700">{{ $post->title }}</a>
        </{{ $headingLevel }}>
        @if ($post->excerpt)
            <p class="mt-2 text-gray-600 text-sm flex-1">{{ $post->excerpt }}</p>
        @endif
        <a href="{{ $post->url() }}" class="mt-4 text-sm font-medium text-primary-700 hover:underline">
            Read more<span class="sr-only"> about {{ $post->title }}</span> →
        </a>
    </div>
</article>

@extends('layouts.public')

@section('seo')
    <x-seo :title="$post->seoTitle()"
           :description="$post->seoDescription()"
           :image="$post->coverImageUrl()"
           type="article"
           :published-time="$post->published_at?->toIso8601String()"
           :modified-time="$post->updated_at?->toIso8601String()"
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), $section['title'] => route($section['route']), $post->title => null]" />

        <article class="mt-6 max-w-3xl">
            <header>
                <h1 class="text-4xl font-bold text-gray-900">{{ $post->title }}</h1>

                @if ($type === \App\Models\Post::TYPE_EVENT)
                    <dl class="mt-4 grid gap-2 text-gray-700 sm:grid-cols-2">
                        @if ($post->event_starts_at)
                            <div>
                                <dt class="text-sm text-gray-500">When</dt>
                                <dd>
                                    <time datetime="{{ $post->event_starts_at->toIso8601String() }}">{{ $post->event_starts_at->format('l, F j, Y · g:i A') }}</time>
                                    @if ($post->event_ends_at)
                                        – <time datetime="{{ $post->event_ends_at->toIso8601String() }}">{{ $post->event_ends_at->isSameDay($post->event_starts_at) ? $post->event_ends_at->format('g:i A') : $post->event_ends_at->format('l, F j, Y · g:i A') }}</time>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm text-gray-500">Where</dt>
                            <dd>{{ $post->location ?: config('seo.site_name') }}</dd>
                        </div>
                    </dl>
                @elseif ($post->published_at)
                    <p class="mt-3 text-sm text-gray-500">
                        Published <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->format('F j, Y') }}</time>
                    </p>
                @endif
            </header>

            @if ($post->coverImageUrl())
                <img src="{{ $post->coverImageUrl() }}" alt="{{ $post->title }}" width="1200" height="630"
                     class="mt-8 w-full rounded-xl object-cover" fetchpriority="high">
            @endif

            <div class="prose-public mt-8 text-gray-800">
                {!! \Illuminate\Support\Str::markdown($post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
            </div>
        </article>

        @if ($related->isNotEmpty())
            <section class="mt-16" aria-labelledby="related-heading">
                <h2 id="related-heading" class="text-2xl font-bold text-gray-900">More {{ strtolower($section['title']) }}</h2>
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $item)
                        <x-post-card :post="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection

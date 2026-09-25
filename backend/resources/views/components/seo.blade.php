@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'type' => 'website',
    'noindex' => false,
    'jsonLd' => [],
    'publishedTime' => null,
    'modifiedTime' => null,
])

@php
    $siteName = config('seo.site_name');
    $fullTitle = $title ? $title.' | '.$siteName : $siteName;
    $description = \Illuminate\Support\Str::limit(trim($description ?: config('seo.default_description')), 160, '…');
    $canonical = \App\Support\Seo::canonical();
    $imageUrl = \App\Support\Seo::absoluteUrl($image ?: config('seo.default_image'));
    $twitter = config('seo.twitter_handle');
@endphp

<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ $noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' }}">
@unless ($noindex)
<link rel="canonical" href="{{ $canonical }}">
@endunless

<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ config('seo.locale') }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:title" content="{{ $title ?: $siteName }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
@if ($imageUrl)
<meta property="og:image" content="{{ $imageUrl }}">
@endif
@if ($publishedTime)
<meta property="article:published_time" content="{{ $publishedTime }}">
@endif
@if ($modifiedTime)
<meta property="article:modified_time" content="{{ $modifiedTime }}">
@endif

<meta name="twitter:card" content="{{ $imageUrl ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title ?: $siteName }}">
<meta name="twitter:description" content="{{ $description }}">
@if ($imageUrl)
<meta name="twitter:image" content="{{ $imageUrl }}">
@endif
@if ($twitter)
<meta name="twitter:site" content="{{ $twitter }}">
@endif

@foreach ($jsonLd as $schema)
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endforeach

@extends('layouts.public')

@section('seo')
    <x-seo :title="$page->seoTitle()"
           :description="$page->seoDescription()"
           type="website"
           :modified-time="$page->updated_at?->toIso8601String()"
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), $page->title => null]" />

        <article class="mt-6 max-w-3xl">
            <header>
                <h1 class="text-4xl font-bold text-gray-900">{{ $page->title }}</h1>
            </header>

            <div class="prose-public mt-8 text-gray-800">
                {!! $page->body !!}
            </div>
        </article>
    </div>
@endsection

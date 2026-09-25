@extends('layouts.public')

@section('seo')
    <x-seo title="Page not found" description="The page you are looking for could not be found." noindex />
@endsection

@section('content')
    <div class="container-page py-24 text-center">
        <p class="text-sm font-semibold text-primary-700">404</p>
        <h1 class="mt-2 text-4xl font-bold text-gray-900">Page not found</h1>
        <p class="mt-4 text-gray-600">Sorry, we couldn't find that page. It may have moved or no longer exists.</p>
        <div class="mt-8 flex justify-center gap-3">
            <a href="{{ route('home') }}" class="btn-public">Go to homepage</a>
            <a href="{{ route('news.index') }}" class="btn-public-outline">Latest news</a>
        </div>
    </div>
@endsection

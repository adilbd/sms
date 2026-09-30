@extends('layouts.public')

@php
    $description = ($former ? 'Former ' : 'Current ').strtolower($title)." at our school.";
@endphp

@section('seo')
    <x-seo :title="$title" :description="$description" :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'School Administration' => route('staff.teachers'), $title => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">{{ $title }}</h1>

        @include('public.staff._shift_pills')

        @if ($members->isEmpty())
            <p class="mt-10 text-gray-500">Nothing here yet. Please check back soon.</p>
        @else
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($members as $member)
                    @include('public.staff._card', ['member' => $member])
                @endforeach
            </div>
        @endif
    </div>
@endsection

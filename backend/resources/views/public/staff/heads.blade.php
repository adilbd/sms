@extends('layouts.public')

@php
    $description = "Meet the {$title} of our school.";
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
            <p class="mt-10 text-gray-500">No {{ strtolower($title) }} has been added yet.</p>
        @else
            <div class="mt-10 space-y-12">
                @foreach ($members as $member)
                    <div class="rounded-xl border border-gray-200 p-6">
                        <h2 class="text-2xl font-semibold text-gray-900">
                            <a href="{{ $member->url() }}" class="hover:text-primary-700">{{ $member->name() }}</a>
                        </h2>
                        <div class="mt-4">
                            @include('public.staff._profile', ['member' => $member])
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection

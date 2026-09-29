@extends('layouts.public')

@section('seo')
    <x-seo :title="$member->seoTitle()"
           :description="$member->seoDescription()"
           type="profile"
           :image="$member->photoUrl()"
           :modified-time="$member->updated_at?->toIso8601String()"
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'School Administration' => route('staff.teachers'), $member->name() => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">{{ $member->name() }}</h1>

        <div class="mt-8">
            @include('public.staff._profile', ['member' => $member])
        </div>
    </div>
@endsection

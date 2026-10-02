@extends('layouts.public')

@section('seo')
    {{-- Private to the family: never indexed, never cached (PortalHeaders sends no-store). --}}
    <x-seo title="আবেদনের অবস্থা (Application status)"
           description="ভর্তির আবেদনের অবস্থা। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
@endsection

@php
    $status = $application->status;
    $tone = match ($status) {
        'approved', 'admitted' => 'bg-green-50 text-green-900',
        'rejected' => 'bg-red-50 text-red-900',
        'waitlisted' => 'bg-amber-50 text-amber-900',
        default => 'bg-blue-50 text-blue-900',
    };
@endphp

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Admissions' => route('admissions'), 'Status' => route('admissions.status')]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">আবেদনের অবস্থা <span class="text-2xl font-medium text-gray-500">(Application status)</span></h1>

        <div class="card-public mt-8 max-w-2xl p-6">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-600">আবেদন নম্বর (Application number)</dt>
                    <dd class="text-base font-semibold text-gray-900">{{ $application->application_no }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">শিক্ষার্থীর নাম (Student)</dt>
                    <dd class="text-base text-gray-900">{{ $application->displayName() }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">শ্রেণি (Class)</dt>
                    <dd>{{ $application->class->name_bn ?: $application->class->name }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">ভর্তি চক্র (Round)</dt>
                    <dd>{{ $application->round->name_bn ?: $application->round->name_en }}</dd>
                </div>
            </dl>

            <p class="mt-6 rounded-lg px-4 py-3 {{ $tone }}" role="status">
                <span class="font-semibold">{{ \App\Models\AdmissionApplication::STATUS_LABELS_BN[$status] }}</span>
                <span class="block text-sm">({{ \App\Models\AdmissionApplication::STATUS_LABELS_EN[$status] }})</span>
            </p>

            @if ($application->test_at)
                <div class="mt-6">
                    <h2 class="text-lg font-semibold text-gray-900">পরীক্ষা / সাক্ষাৎকার <span class="text-sm font-medium text-gray-500">(Test or interview)</span></h2>
                    <dl class="mt-2 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-gray-600">সময় (Date and time)</dt>
                            <dd>{{ \App\Support\BanglaDate::dateTime($application->test_at) }}</dd>
                        </div>
                        @if ($application->test_venue)
                            <div>
                                <dt class="font-medium text-gray-600">স্থান (Venue)</dt>
                                <dd>{{ $application->test_venue }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('admissions.status') }}" class="btn-public-outline">আবার খুঁজুন (Search again)</a>
                <a href="{{ route('admissions') }}" class="btn-public-outline">ভর্তি পাতা (Admissions)</a>
            </div>
        </div>
    </div>
@endsection

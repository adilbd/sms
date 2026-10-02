@extends('layouts.public')

@section('seo')
    {{-- Private lookup: never indexed, never cached (PortalHeaders sends no-store). --}}
    <x-seo title="আবেদনের অবস্থা (Application status)"
           description="আবেদন নম্বর ও জন্ম তারিখ দিয়ে ভর্তির আবেদনের অবস্থা দেখুন। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Admissions' => route('admissions'), 'Status' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">আবেদনের অবস্থা <span class="text-2xl font-medium text-gray-500">(Application status)</span></h1>
        <p class="mt-3 max-w-2xl text-gray-600">
            আবেদন জমা দেওয়ার সময় পাওয়া আবেদন নম্বর এবং শিক্ষার্থীর জন্ম তারিখ দিন। জন্ম তারিখ ছাড়া কোনো তথ্য দেখানো হয় না।
        </p>

        @if ($errors->has('lookup'))
            <p class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-red-800" role="alert">{{ $errors->first('lookup') }}</p>
        @endif

        <form method="POST" action="{{ route('admissions.status.show') }}" class="card-public mt-8 grid max-w-2xl gap-5 p-6 sm:grid-cols-2" novalidate>
            @csrf

            <div>
                <label for="application_no" class="block text-sm font-medium text-gray-700">আবেদন নম্বর (Application number)</label>
                <input id="application_no" name="application_no" type="text" maxlength="30" value="{{ old('application_no') }}" placeholder="ADM-2026-000001" autocomplete="off" class="field mt-1" required>
                @error('application_no') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="date_of_birth" class="block text-sm font-medium text-gray-700">জন্ম তারিখ (Date of birth)</label>
                <input id="date_of_birth" name="date_of_birth" type="date" autocomplete="off" class="field mt-1" required>
                @error('date_of_birth') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <button type="submit" class="btn-public">অবস্থা দেখুন (Check status)</button>
            </div>
        </form>
    </div>
@endsection

@extends('layouts.public')

@section('seo')
    {{-- Private to the family: never indexed, never cached (PortalHeaders sends no-store). --}}
    <x-seo title="আবেদন জমা হয়েছে (Application received)"
           description="ভর্তির আবেদন জমা হয়েছে। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
@endsection

@section('content')
    <div class="container-page py-12">
        <h1 class="text-4xl font-bold text-gray-900">আবেদন জমা হয়েছে <span class="text-2xl font-medium text-gray-500">(Application received)</span></h1>

        <div class="card-public mt-8 max-w-2xl p-6">
            <p class="text-gray-700">
                {{ $application->displayName() }}-এর ভর্তির আবেদন গ্রহণ করা হয়েছে।
                <span class="block text-sm text-gray-500">(The admission application has been received.)</span>
            </p>

            <p class="mt-6 text-sm font-medium text-gray-600">আপনার আবেদন নম্বর (Your application number)</p>
            <p class="mt-1 text-3xl font-bold tracking-wide text-primary-700" id="application-no">{{ $application->application_no }}</p>

            <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-600">শ্রেণি (Class)</dt>
                    <dd>{{ $application->class->name_bn ?: $application->class->name }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">ভর্তি চক্র (Round)</dt>
                    <dd>{{ $application->round->name_bn ?: $application->round->name_en }}</dd>
                </div>
            </dl>

            <p class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
                এই নম্বরটি সংরক্ষণ করুন। পরে আবেদনের অবস্থা জানতে এই নম্বর ও শিক্ষার্থীর জন্ম তারিখ লাগবে।
                <span class="block">(Keep this number. You need it with the child's date of birth to check the status.)</span>
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('admissions.status') }}" class="btn-public">আবেদনের অবস্থা দেখুন (Check status)</a>
                <a href="{{ route('admissions') }}" class="btn-public-outline">ভর্তি পাতায় ফিরুন (Back to admissions)</a>
            </div>
        </div>
    </div>
@endsection

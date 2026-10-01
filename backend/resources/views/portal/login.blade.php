@extends('layouts.public')

@section('seo')
    <x-seo title="পোর্টাল লগইন (Portal login)"
           description="শিক্ষার্থী ও অভিভাবকদের জন্য পোর্টাল: ফলাফল, উপস্থিতি ও ফি দেখুন। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
@endsection

@section('content')
    <div class="container-page py-12">
        <div class="mx-auto max-w-md">
            <h1 class="text-3xl font-bold text-gray-900">পোর্টাল লগইন <span class="block text-lg font-medium text-gray-500">(Student &amp; guardian portal)</span></h1>
            <p class="mt-3 text-gray-600">শিক্ষার্থী আইডি অথবা অভিভাবকের মোবাইল নম্বর ও পাসওয়ার্ড দিন।</p>

            @if ($errors->any())
                <p class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-red-800" role="alert">
                    লগইন ব্যর্থ হয়েছে। আইডি/মোবাইল ও পাসওয়ার্ড ঠিক আছে কিনা দেখুন। (Sign-in failed. Check your ID or mobile number and your password.)
                </p>
            @endif

            <form method="POST" action="{{ route('portal.login.store') }}" class="card-public mt-6 grid gap-5 p-6">
                @csrf
                <div>
                    <label for="login" class="block text-sm font-medium text-gray-700">শিক্ষার্থী আইডি / মোবাইল (Student ID / mobile)</label>
                    <input id="login" name="login" type="text" value="{{ old('login') }}" required autocomplete="username" inputmode="text" class="field mt-1">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">পাসওয়ার্ড (Password)</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="field mt-1">
                </div>
                <button type="submit" class="btn-public w-full">লগইন (Sign in)</button>
            </form>

            <p class="mt-6 text-sm text-gray-500">শিক্ষক ও কর্মচারীরা <a href="{{ url('/admin/login') }}" class="text-primary-700 hover:underline" rel="nofollow">অ্যাডমিন লগইন</a> ব্যবহার করুন।</p>
        </div>
    </div>
@endsection

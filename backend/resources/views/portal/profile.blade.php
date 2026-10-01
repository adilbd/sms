@extends('portal.layout')

@section('page_title', 'প্রোফাইল')
@section('heading', 'প্রোফাইল')

@section('portal')
    @php
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;

        $rows = [
            'জন্ম তারিখ' => $student->date_of_birth ? BanglaDate::date($student->date_of_birth->toDateString()) : '-',
            'পিতার নাম' => $student->father_name_bn ?: $student->father_name_en ?: '-',
            'মাতার নাম' => $student->mother_name_bn ?: $student->mother_name_en ?: '-',
            'অভিভাবক' => $student->guardian_name ?: '-',
            'অভিভাবকের মোবাইল' => $student->guardian_mobile ? BanglaNumber::format($student->guardian_mobile) : '-',
        ];
    @endphp

    @include('portal._student', ['student' => $student])

    <dl class="mt-6 card-public grid gap-4 p-5 sm:grid-cols-2">
        @foreach ($rows as $label => $value)
            <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="font-semibold text-gray-900">{{ $value }}</dd></div>
        @endforeach
    </dl>

    <section class="mt-8 max-w-xl" aria-labelledby="pw-title">
        <h2 id="pw-title" class="text-xl font-semibold text-gray-900">পাসওয়ার্ড পরিবর্তন</h2>

        @if (session('status') === 'password-changed')
            <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-green-800" role="status">পাসওয়ার্ড পরিবর্তন হয়েছে। অন্য ডিভাইসে আবার লগইন করতে হবে।</p>
        @endif

        <form method="POST" action="{{ route('portal.password') }}" class="card-public mt-4 grid gap-4 p-5">
            @csrf
            @method('PUT')
            @foreach ([['current_password', 'বর্তমান পাসওয়ার্ড', 'current-password'], ['new_password', 'নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)', 'new-password'], ['new_password_confirmation', 'নতুন পাসওয়ার্ড আবার লিখুন', 'new-password']] as [$name, $label, $autocomplete])
                <div>
                    <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                    <input id="{{ $name }}" name="{{ $name }}" type="password" required autocomplete="{{ $autocomplete }}" class="field mt-1">
                    @error($name)<p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <button type="submit" class="btn-public">পাসওয়ার্ড পরিবর্তন করুন</button>
        </form>
    </section>
@endsection

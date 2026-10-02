@extends('layouts.public')

@section('seo')
    <x-seo title="Admissions"
           description="ভর্তির আবেদন অনলাইনে করুন: খোলা ভর্তি চক্র, শ্রেণি ও আসন সংখ্যা, প্রয়োজনীয় কাগজপত্র এবং আবেদনের অবস্থা জানার উপায়। Apply online for admission."
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Admissions' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">ভর্তি <span class="text-2xl font-medium text-gray-500">(Admissions)</span></h1>
        <p class="mt-3 max-w-3xl text-lg text-gray-600">
            প্রথম থেকে দ্বাদশ শ্রেণিতে ভর্তির আবেদন এখন অনলাইনে করা যায়। খোলা ভর্তি চক্র বেছে নিয়ে আবেদন ফর্মটি পূরণ করুন।
            <span class="block text-base text-gray-500">(Apply online for Class 1 to Class 12 by choosing an open admission round below.)</span>
        </p>

        <section class="mt-10" aria-labelledby="open-rounds">
            <h2 id="open-rounds" class="text-2xl font-semibold text-gray-900">আবেদন চলছে <span class="text-lg font-medium text-gray-500">(Open for applications)</span></h2>

            @forelse ($rounds as $round)
                <article class="card-public mt-5 p-6" aria-labelledby="round-{{ $round->id }}">
                    <h3 id="round-{{ $round->id }}" class="text-xl font-semibold text-gray-900">
                        {{ $round->name_bn ?: $round->name_en }}
                        @if ($round->name_bn && $round->name_en)
                            <span class="text-base font-medium text-gray-500">({{ $round->name_en }})</span>
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        শিক্ষাবর্ষ {{ \App\Support\BanglaNumber::format($round->academicYear->year) }}
                        · আবেদনের শেষ তারিখ: <strong>{{ \App\Support\BanglaDate::date($round->closes_at->toDateString()) }}</strong>
                        <span class="text-gray-500">(Deadline: {{ $round->closes_at->format('j M Y') }})</span>
                    </p>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full max-w-xl text-left text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-gray-600">
                                    <th class="py-2 pr-4 font-medium">শ্রেণি (Class)</th>
                                    <th class="py-2 font-medium">আসন সংখ্যা (Seats)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($round->classes->sortBy(fn ($row) => $row->class->number) as $row)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4">{{ $row->class->name_bn ?: $row->class->name }}</td>
                                        <td class="py-2">{{ $row->seats === null ? 'সীমাহীন (Open)' : \App\Support\BanglaNumber::format($row->seats) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($round->instructions_bn || $round->instructions_en)
                        <div class="prose-public mt-4 max-w-3xl">
                            {{-- Sanitized HTML written in the admin editor (PostBody::sanitize). --}}
                            @if ($round->instructions_bn){!! $round->instructions_bn !!}@endif
                            @if ($round->instructions_en)<div lang="en">{!! $round->instructions_en !!}</div>@endif
                        </div>
                    @endif

                    <div class="mt-5">
                        <a href="{{ $round->url() }}" class="btn-public">আবেদন করুন (Apply now)</a>
                    </div>
                </article>
            @empty
                <p class="mt-4 text-gray-600">
                    এই মুহূর্তে কোনো ভর্তি চক্র খোলা নেই। নতুন ভর্তির ঘোষণার জন্য সাইটটি দেখুন বা
                    <a href="{{ route('contact') }}" class="font-medium text-primary-700 hover:underline">আমাদের সাথে যোগাযোগ করুন</a>।
                    <span class="block text-sm text-gray-500">(No admission round is open right now. Please check back or contact the school office.)</span>
                </p>
            @endforelse
        </section>

        <section class="prose-public mt-10 max-w-3xl" aria-labelledby="how-to-apply">
            <h2 id="how-to-apply">কীভাবে আবেদন করবেন <span class="text-lg font-medium text-gray-500">(How to apply)</span></h2>
            <ol class="list-decimal pl-6 space-y-1">
                <li>খোলা ভর্তি চক্রে "আবেদন করুন" চাপুন এবং ফর্মটি পূরণ করুন। (Open the form for a round and fill it in.)</li>
                <li>শিক্ষার্থীর ছবি যুক্ত করুন। জন্ম নিবন্ধন সনদ ও আগের স্কুলের কাগজ চাইলে যুক্ত করতে পারেন। (Attach the student photo; the birth certificate and previous school papers are optional.)</li>
                <li>জমা দেওয়ার পর পাওয়া <strong>আবেদন নম্বর</strong> সংরক্ষণ করুন। (Keep the application number you receive.)</li>
                <li>বিদ্যালয় আবেদন যাচাই করে প্রয়োজনে পরীক্ষা বা সাক্ষাৎকারের সময় জানাবে। (The school reviews the application and may schedule a test or interview.)</li>
                <li>ফলাফল জানতে আবেদন নম্বর ও জন্ম তারিখ দিয়ে অবস্থা দেখুন। (Check the outcome with the number and date of birth.)</li>
            </ol>

            <h2>প্রয়োজনীয় কাগজপত্র <span class="text-lg font-medium text-gray-500">(Documents required)</span></h2>
            <ul>
                <li>শিক্ষার্থীর সাম্প্রতিক ছবি, JPG বা PNG, সর্বোচ্চ ২ মেগাবাইট (বাধ্যতামূলক) <span class="text-gray-500">(Recent passport-size photo, required)</span></li>
                <li>জন্ম নিবন্ধন সনদের স্ক্যান, ঐচ্ছিক <span class="text-gray-500">(Birth certificate scan, optional)</span></li>
                <li>আগের স্কুলের ছাড়পত্র বা প্রতিবেদন, ঐচ্ছিক <span class="text-gray-500">(Previous school TC or report, optional)</span></li>
                <li>জন্ম নিবন্ধন নম্বর (১৭ অঙ্ক) ফর্মে লিখতে হবে <span class="text-gray-500">(17-digit birth registration number)</span></li>
            </ul>

            <h2>ফি <span class="text-lg font-medium text-gray-500">(Fees)</span></h2>
            <p>
                আবেদনের জন্য কোনো ফি নেই। ভর্তি অনুমোদনের পর প্রযোজ্য ফি বিদ্যালয় অফিসে পরিশোধ করতে হবে।
                <span class="block text-sm text-gray-500">(There is no application fee. Any admission fee is paid at the school office after approval.)</span>
            </p>
        </section>

        <div class="mt-10 flex flex-wrap gap-3">
            <a href="{{ route('admissions.status') }}" class="btn-public-outline">আবেদনের অবস্থা দেখুন (Check application status)</a>
            <a href="{{ route('contact') }}" class="btn-public-outline">যোগাযোগ করুন (Contact us)</a>
        </div>
    </div>
@endsection

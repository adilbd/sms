@extends('layouts.public')

@section('seo')
    <x-seo title="ফলাফলের আর্কাইভ (Result archive)"
           description="বছর অনুযায়ী প্রকাশিত সব পরীক্ষার তালিকা। পরীক্ষা বেছে নিয়ে ফলাফল দেখুন।"
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Results' => route('results.index'), 'Result archive' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">ফলাফলের আর্কাইভ <span class="text-2xl font-medium text-gray-500">(Result archive)</span></h1>

        @if ($years->isEmpty())
            <p class="mt-10 text-gray-500">এখনো কোনো ফলাফল প্রকাশিত হয়নি। (No results have been published yet.)</p>
        @else
            <div class="mt-10 space-y-10">
                @foreach ($years as $year => $exams)
                    <section aria-labelledby="year-{{ $year }}">
                        <h2 id="year-{{ $year }}" class="text-2xl font-semibold text-gray-900">{{ $year }}</h2>
                        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($exams as $exam)
                                <li class="card-public p-4">
                                    <a href="{{ route('results.index', ['exam_id' => $exam->id]) }}" class="font-medium text-primary-700 hover:underline">
                                        {{ $exam->name_bn ?: $exam->name_en }}
                                    </a>
                                    @if ($exam->name_bn && $exam->name_en)
                                        <p class="text-sm text-gray-500">{{ $exam->name_en }}</p>
                                    @endif
                                    @if ($exam->published_at)
                                        <p class="mt-1 text-xs text-gray-500">প্রকাশিত (Published): {{ $exam->published_at->timezone('Asia/Dhaka')->format('d M Y') }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection

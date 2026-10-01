@extends('layouts.public')

@section('seo')
    {{-- Private to the family: never indexed, never cached (ResultController sends no-store). --}}
    <x-seo :title="'ফলাফল / Result'"
           description="শিক্ষার্থীর ব্যক্তিগত পরীক্ষার ফলাফল ও মার্কশিট। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap">
    <style>
        @page { size: {{ $paper === 'legal' ? 'legal' : 'A4' }} {{ $orientation }}; margin: 12mm; }
        @media print {
            body > header, body > footer, body > a.sr-only, .no-print { display: none !important; }
            .marksheet { border: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; max-width: none !important; }
        }
    </style>
@endsection

@section('content')
    <div class="container-page py-8">
        <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
            <h1 class="text-2xl font-bold text-gray-900">ফলাফল <span class="text-lg font-medium text-gray-500">(Result)</span></h1>
            <div class="flex gap-2">
                <a href="{{ route('results.index', ['exam_id' => $result->exam_id]) }}" class="btn-public-outline">আবার খুঁজুন (Search again)</a>
                <button type="button" class="btn-public" onclick="window.print()">প্রিন্ট (Print)</button>
            </div>
        </div>

        @include('public.results.marksheet', ['result' => $result, 'language' => $language, 'orientation' => $orientation])
    </div>
@endsection

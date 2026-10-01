@extends('portal.layout')

@section('page_title', 'মার্কশিট')
@section('heading', 'মার্কশিট')

@section('head')
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

@section('portal')
    <form method="GET" action="{{ route('portal.result', $result->exam_id) }}" class="no-print mb-6 flex flex-wrap items-end gap-3">
        @foreach ([
            ['language', 'ভাষা', ['bn' => 'বাংলা', 'en' => 'English'], $language],
            ['page', 'কাগজ', ['a4' => 'A4', 'legal' => 'Legal'], $paper],
            ['orientation', 'দিক', ['portrait' => 'পোর্ট্রেট', 'landscape' => 'ল্যান্ডস্কেপ'], $orientation],
        ] as [$name, $label, $options, $current])
            <label class="text-xs text-gray-600">{{ $label }}
                <select name="{{ $name }}" class="field mt-0.5 !py-1.5">
                    @foreach ($options as $value => $text)
                        <option value="{{ $value }}" @selected($current === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <button type="submit" class="btn-public-outline !px-4 !py-2 text-sm">প্রয়োগ করুন</button>
        <button type="button" class="btn-public !px-4 !py-2 text-sm" onclick="window.print()">প্রিন্ট</button>
    </form>

    <div class="overflow-x-auto">
        @include('public.results.marksheet', ['result' => $result, 'language' => $language, 'orientation' => $orientation])
    </div>
@endsection

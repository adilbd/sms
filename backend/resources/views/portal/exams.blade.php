@extends('portal.layout')

@section('page_title', 'পরীক্ষার সূচি')
@section('heading', 'পরীক্ষার সূচি')

@section('portal')
    @php
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;
        use App\Support\ExamTitle;

        $time = function (?string $value) {
            if (! $value) {
                return '';
            }
            [$h, $m] = explode(':', $value);

            return BanglaNumber::format(((int) $h % 12) ?: 12).':'.BanglaNumber::format($m).' '.((int) $h < 12 ? 'পূর্বাহ্ন' : 'অপরাহ্ন');
        };
    @endphp

    @forelse ($exams as $entry)
        <section class="card-public mb-4 p-5">
            <h2 class="text-lg font-semibold text-gray-900">{{ ExamTitle::withYear($entry['exam']->name_bn ?: $entry['exam']->name_en, $entry['exam']->start_date?->year, true) }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b border-gray-200 text-gray-500"><th class="py-2 pr-4 font-medium">বিষয়</th><th class="py-2 pr-4 font-medium">তারিখ</th><th class="py-2 font-medium">সময়</th></tr></thead>
                    <tbody>
                        @foreach ($entry['subjects'] as $subject)
                            <tr class="border-b border-gray-100">
                                <td class="py-2 pr-4 font-medium text-gray-900">{{ $subject->subject?->name_bn ?: $subject->subject?->name }}</td>
                                <td class="py-2 pr-4">{{ $subject->exam_date ? BanglaDate::date($subject->exam_date->toDateString()) : '-' }}</td>
                                <td class="py-2">{{ $subject->start_time ? $time(substr((string) $subject->start_time, 0, 5)).($subject->end_time ? ' – '.$time(substr((string) $subject->end_time, 0, 5)) : '') : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <p class="text-gray-500">এই মুহূর্তে কোনো পরীক্ষার সূচি নেই।</p>
    @endforelse
@endsection

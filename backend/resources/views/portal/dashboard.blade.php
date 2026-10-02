@extends('portal.layout')

@section('page_title', 'পোর্টাল')
@section('heading', 'স্বাগতম')

@section('portal')
    @php
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;
        use App\Support\ExamTitle;
        use App\Support\Money;

        $attendance = $summary['attendance'];
        $result = $summary['latest_result'];
        $fees = $summary['fees'];
        $dueCount = $fees['dues']->filter(fn ($due) => $due->outstandingPaisa() > 0)->count();
    @endphp

    @include('portal._student', ['student' => $student])

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <section class="card-public p-5" aria-labelledby="att-title">
            <h2 id="att-title" class="text-sm font-medium text-gray-500">এই মাসের উপস্থিতি</h2>
            <p class="mt-2 text-3xl font-bold text-gray-900" data-testid="attendance-percentage">{{ BanglaNumber::format($attendance['percentage']) }}%</p>
            <p class="mt-1 text-sm text-gray-500">{{ BanglaDate::month($attendance['month']) }}</p>
            <a href="{{ route('portal.attendance') }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">বিস্তারিত</a>
        </section>

        <section class="card-public p-5" aria-labelledby="res-title">
            <h2 id="res-title" class="text-sm font-medium text-gray-500">সর্বশেষ ফলাফল</h2>
            @if ($result)
                <p class="mt-2 text-3xl font-bold text-gray-900" data-testid="latest-gpa">জিপিএ {{ BanglaNumber::format(number_format((float) $result->gpa, 2, '.', '')) }} <span class="text-xl text-gray-500">({{ $result->grade }})</span></p>
                <p class="mt-1 text-sm text-gray-500">{{ ExamTitle::withYear($result->exam->name_bn ?: $result->exam->name_en, $result->exam->academicYear->year, true) }}</p>
                <a href="{{ route('portal.result', $result->exam_id) }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">মার্কশিট দেখুন</a>
            @else
                <p class="mt-2 text-gray-500">এখনো কোনো ফলাফল প্রকাশিত হয়নি।</p>
            @endif
        </section>

        <section class="card-public p-5" aria-labelledby="fee-title">
            <h2 id="fee-title" class="text-sm font-medium text-gray-500">বকেয়া ফি</h2>
            <p class="mt-2 text-3xl font-bold {{ Money::toPaisa($fees['outstanding_total']) > 0 ? 'text-red-700' : 'text-gray-900' }}" data-testid="outstanding-total">{{ Money::display($fees['outstanding_total']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $dueCount > 0 ? BanglaNumber::format($dueCount).'টি বকেয়া' : 'কোনো বকেয়া নেই' }}</p>
            <a href="{{ route('portal.fees') }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">বিস্তারিত</a>
        </section>

        <section class="card-public p-5" aria-labelledby="hw-title">
            <h2 id="hw-title" class="text-sm font-medium text-gray-500">এই সপ্তাহের হোমওয়ার্ক</h2>
            <p class="mt-2 text-3xl font-bold text-gray-900" data-testid="homework-due-count">{{ BanglaNumber::format($summary['homework_due_this_week']->count()) }}টি</p>
            <p class="mt-1 text-sm text-gray-500">আগামী ৭ দিনে জমা দিতে হবে</p>
            <a href="{{ route('portal.homework') }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">বিস্তারিত</a>
        </section>
    </div>

    <section class="mt-6 card-public p-5" aria-labelledby="exam-title">
        <h2 id="exam-title" class="text-lg font-semibold text-gray-900">আসন্ন পরীক্ষা</h2>
        @forelse ($summary['exams'] as $entry)
            @php $first = collect($entry['subjects'])->map(fn ($s) => $s->exam_date?->toDateString())->filter()->min(); @endphp
            <p class="mt-3 text-gray-800">
                <span class="font-medium">{{ ExamTitle::withYear($entry['exam']->name_bn ?: $entry['exam']->name_en, $entry['exam']->start_date?->year, true) }}</span>
                @if ($first)<span class="text-gray-500"> – শুরু {{ BanglaDate::date($first) }}</span>@endif
            </p>
        @empty
            <p class="mt-2 text-gray-500">এই মুহূর্তে কোনো পরীক্ষার সূচি নেই।</p>
        @endforelse
        <a href="{{ route('portal.exams') }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">পরীক্ষার সূচি দেখুন</a>
    </section>
@endsection

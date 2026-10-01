@extends('portal.layout')

@section('page_title', 'উপস্থিতি')
@section('heading', 'উপস্থিতি')

@section('portal')
    @php
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;
        use Carbon\CarbonImmutable;

        $labels = ['present' => 'উপস্থিত', 'absent' => 'অনুপস্থিত', 'late' => 'বিলম্ব', 'leave' => 'ছুটি'];
        $styles = [
            'present' => 'bg-green-50 text-green-800',
            'absent' => 'bg-red-50 text-red-800',
            'late' => 'bg-amber-50 text-amber-800',
            'leave' => 'bg-blue-50 text-blue-800',
            'holiday' => 'bg-purple-50 text-purple-800',
            'weekly' => 'bg-gray-100 text-gray-600',
        ];
        $first = CarbonImmutable::createFromFormat('!Y-m', $month['month'], 'UTC');
        // Saturday-first week, as in Bangladesh: Carbon's Sunday = 0 becomes column 1.
        $offset = ($first->dayOfWeek + 1) % 7;
        $weekdays = ['শনি', 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহ', 'শুক্র'];
        $ytd = $month['year_to_date'];
    @endphp

    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <a href="{{ route('portal.attendance', ['month' => $previousMonth]) }}" class="btn-public-outline !px-3 !py-1.5 text-sm" rel="prev">&larr; আগের মাস</a>
            <a href="{{ route('portal.attendance', ['month' => $nextMonth]) }}" class="btn-public-outline !px-3 !py-1.5 text-sm" rel="next">পরের মাস &rarr;</a>
        </div>
        <p class="text-lg font-semibold text-gray-900">{{ BanglaDate::month($month['month']) }}</p>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach ($labels as $status => $label)
            <div class="card-public p-3 text-center">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="text-2xl font-bold text-gray-900" data-testid="total-{{ $status }}">{{ BanglaNumber::format($month['totals'][$status]) }}</p>
            </div>
        @endforeach
        <div class="card-public col-span-2 p-3 text-center sm:col-span-1">
            <p class="text-xs text-gray-500">উপস্থিতির হার</p>
            <p class="text-2xl font-bold text-primary-700" data-testid="month-percentage">{{ BanglaNumber::format($month['percentage']) }}%</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-7 gap-1 text-center text-xs sm:gap-2" role="table" aria-label="মাসের উপস্থিতি">
        @foreach ($weekdays as $weekday)
            <div class="py-1 font-medium text-gray-500" role="columnheader">{{ $weekday }}</div>
        @endforeach
        @for ($i = 0; $i < $offset; $i++)
            <div aria-hidden="true"></div>
        @endfor
        @for ($day = 1; $day <= $first->daysInMonth; $day++)
            @php
                $date = $first->day($day)->toDateString();
                $status = $month['days'][$date] ?? null;
                $off = $month['non_school_days'][$date] ?? null;
                $key = $status ?? $off['type'] ?? null;
                $text = $status ? $labels[$status] : ($off ? ($off['type'] === 'weekly' ? 'সাপ্তাহিক ছুটি' : ($off['name_bn'] ?: $off['name_en'] ?: 'ছুটির দিন')) : '');
            @endphp
            <div class="min-h-[3.5rem] rounded-lg p-1 sm:min-h-[4.5rem] {{ $key ? $styles[$key] : 'bg-white border border-gray-100 text-gray-400' }}" role="cell" data-date="{{ $date }}" @if ($key) data-status="{{ $key }}" @endif>
                <span class="block text-sm font-semibold">{{ BanglaNumber::format($day) }}</span>
                <span class="block leading-tight">{{ $text }}</span>
            </div>
        @endfor
    </div>

    <section class="card-public mt-8 p-5" aria-labelledby="ytd-title">
        <h2 id="ytd-title" class="text-lg font-semibold text-gray-900">বছরের শুরু থেকে এ পর্যন্ত</h2>
        <p class="mt-1 text-sm text-gray-500">মোট স্কুল দিন: {{ BanglaNumber::format($ytd['school_days']) }} · উপস্থিতির হার: <strong data-testid="ytd-percentage">{{ BanglaNumber::format($ytd['percentage']) }}%</strong></p>
        <p class="mt-2 text-sm text-gray-700">
            @foreach ($labels as $status => $label){{ $label }} {{ BanglaNumber::format($ytd['totals'][$status]) }}@if (! $loop->last) · @endif @endforeach
        </p>
    </section>
@endsection

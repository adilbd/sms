@extends('portal.layout')

@section('page_title', 'রসিদ')
@section('heading', 'রসিদ')

@section('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap">
    <style>
        @page { size: {{ $paper === 'a5' ? 'A5 landscape' : 'A4 portrait' }}; margin: {{ $paper === 'a5' ? '8mm' : '12mm' }}; }
        @media print {
            body > header, body > footer, body > a.sr-only, .no-print { display: none !important; }
            .fee-receipt { border: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; max-width: none !important; }
        }
    </style>
@endsection

@section('portal')
    @php
        use App\Models\FeePayment;
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;
        use App\Support\Money;

        $bn = $language === 'bn';
        $t = fn (string $bangla, string $english) => $bn ? $bangla : $english;
        $d = fn ($value) => $bn ? BanglaNumber::format($value) : (string) $value;
        $pick = fn (?string $banglaName, ?string $englishName) => $bn ? ($banglaName ?: $englishName) : ($englishName ?: $banglaName);
        $dhaka = $payment->paid_at->copy()->setTimezone(BanglaDate::TIMEZONE);
        $date = $bn ? BanglaDate::dateTime($payment->paid_at) : $dhaka->format('d M Y, h:i a');
        $enrolment = $payment->allocations->first()?->due?->enrolment;
        $address = collect([$institute['village'] ?: $institute['street'], $institute['upazila'], $institute['district']])->filter()->implode(', ');
        $period = function (?string $p) use ($bn, $d) {
            if (! $p) {
                return '';
            }
            if ($p === 'one_time') {
                return $bn ? 'এককালীন' : 'One-time';
            }
            if (str_starts_with($p, 'exam:')) {
                return $bn ? 'পরীক্ষা' : 'Exam';
            }
            [$year, $m] = explode('-', $p);

            return $bn ? BanglaDate::MONTHS[(int) $m - 1].' '.BanglaNumber::format($year) : date('F', mktime(0, 0, 0, (int) $m, 1)).' '.$year;
        };
        $method = $bn ? (FeePayment::METHOD_LABELS_BN[$payment->method] ?? $payment->method) : ucfirst($payment->method);
    @endphp

    <form method="GET" action="{{ route('portal.receipt', $payment->id) }}" class="no-print mb-6 flex flex-wrap items-end gap-3">
        <label class="text-xs text-gray-600">ভাষা
            <select name="language" class="field mt-0.5 !py-1.5"><option value="bn" @selected($bn)>বাংলা</option><option value="en" @selected(! $bn)>English</option></select>
        </label>
        <label class="text-xs text-gray-600">কাগজ
            <select name="page" class="field mt-0.5 !py-1.5"><option value="a4" @selected($paper === 'a4')>A4</option><option value="a5" @selected($paper === 'a5')>A5</option></select>
        </label>
        <button type="submit" class="btn-public-outline !px-4 !py-2 text-sm">প্রয়োগ করুন</button>
        <button type="button" class="btn-public !px-4 !py-2 text-sm" onclick="window.print()">প্রিন্ট</button>
    </form>

    <article class="fee-receipt relative mx-auto max-w-3xl overflow-hidden rounded-lg border border-gray-300 bg-white p-6 text-[13px] leading-snug text-gray-900" style="font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif" lang="{{ $language }}">
        @if ($payment->isCancelled())
            <div class="pointer-events-none absolute inset-0 flex -rotate-[24deg] items-center justify-center text-7xl font-extrabold tracking-widest text-red-700/20" aria-hidden="true">{{ $t('বাতিল', 'CANCELLED') }}</div>
        @endif

        <header class="flex items-center justify-center gap-4 text-center">
            @if ($institute['logo_url'])<img src="{{ $institute['logo_url'] }}" alt="" class="h-14 w-14 object-contain">@endif
            <div>
                <h2 class="text-lg font-bold">{{ $pick($institute['name_bn'], $institute['name_en']) }}</h2>
                @if ($address)<p class="text-xs text-gray-500">{{ $address }}</p>@endif
            </div>
        </header>

        <h3 class="my-3 border-y border-gray-900 py-1 text-center text-[15px] font-bold">{{ $t('ফি আদায়ের রসিদ', 'Fee Receipt') }}</h3>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-4">
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('রসিদ নং', 'Receipt no.') }}</dt><dd class="font-semibold" data-testid="receipt-no">{{ $d($payment->receipt_no) }}</dd></div>
            <div class="sm:col-span-3"><dt class="text-[10px] uppercase text-gray-500">{{ $t('তারিখ', 'Date') }}</dt><dd class="font-semibold" data-testid="receipt-date">{{ $date }}</dd></div>
            <div class="col-span-2"><dt class="text-[10px] uppercase text-gray-500">{{ $t('শিক্ষার্থীর নাম', 'Student') }}</dt><dd class="font-semibold">{{ $pick($payment->student->name_bn, $payment->student->name_en) }}</dd></div>
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('আইডি', 'Student ID') }}</dt><dd class="font-semibold">{{ $d($payment->student->student_id) }}</dd></div>
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('শ্রেণি', 'Class') }}</dt><dd class="font-semibold">{{ $pick($enrolment?->class?->name_bn, $enrolment?->class?->name) ?: '-' }}</dd></div>
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('সেকশন', 'Section') }}</dt><dd class="font-semibold">{{ $enrolment?->section?->name ?? '-' }}</dd></div>
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('রোল', 'Roll') }}</dt><dd class="font-semibold">{{ $enrolment?->roll_number !== null ? $d($enrolment->roll_number) : '-' }}</dd></div>
        </dl>

        <table class="my-3 w-full border-collapse">
            <thead><tr class="bg-gray-100">
                <th class="border border-gray-400 px-2 py-1 text-left">{{ $t('ক্রম', '#') }}</th>
                <th class="border border-gray-400 px-2 py-1 text-left">{{ $t('খাত', 'Fee') }}</th>
                <th class="border border-gray-400 px-2 py-1 text-left">{{ $t('মাস / সময়', 'Month / period') }}</th>
                <th class="border border-gray-400 px-2 py-1 text-right">{{ $t('টাকা', 'Amount') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($payment->allocations as $allocation)
                    <tr>
                        <td class="border border-gray-400 px-2 py-1">{{ $d($loop->iteration) }}</td>
                        <td class="border border-gray-400 px-2 py-1">{{ $pick($allocation->due?->head?->name_bn, $allocation->due?->head?->name_en) }}</td>
                        <td class="border border-gray-400 px-2 py-1">{{ $period($allocation->due?->period) }}</td>
                        <td class="border border-gray-400 px-2 py-1 text-right">{{ Money::display($allocation->amount, $bn) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr>
                <td colspan="3" class="border border-gray-400 px-2 py-1 text-right font-bold">{{ $t('মোট', 'Total') }}</td>
                <td class="border border-gray-400 px-2 py-1 text-right font-bold" data-testid="receipt-total">{{ Money::display($payment->amount, $bn) }}</td>
            </tr></tfoot>
        </table>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 border-t border-dashed border-gray-400 pt-2 sm:grid-cols-4">
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('পরিশোধের মাধ্যম', 'Method') }}</dt><dd class="font-semibold" data-testid="receipt-method">{{ $method }}</dd></div>
            @if ($payment->transaction_id)
                <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('ট্রানজেকশন আইডি', 'Transaction ID') }}</dt><dd class="font-semibold">{{ $payment->transaction_id }}</dd></div>
            @endif
            <div><dt class="text-[10px] uppercase text-gray-500">{{ $t('আদায়কারী', 'Collected by') }}</dt><dd class="font-semibold">{{ $payment->collector?->name }}</dd></div>
        </dl>

        @if ($payment->isCancelled())
            <p class="mt-3 font-semibold text-red-700">{{ $t('এই রসিদ বাতিল করা হয়েছে', 'This receipt has been cancelled') }}@if ($payment->cancel_reason): {{ $payment->cancel_reason }}@endif</p>
        @endif

        <footer class="mt-10 grid grid-cols-2 gap-12 text-center text-xs">
            <div><span class="mb-1 block border-t border-gray-900"></span>{{ $t('অভিভাবক', 'Guardian') }}</div>
            <div><span class="mb-1 block border-t border-gray-900"></span>{{ $t('আদায়কারীর স্বাক্ষর', 'Received by') }}</div>
        </footer>
    </article>
@endsection

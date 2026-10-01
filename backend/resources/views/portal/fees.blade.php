@extends('portal.layout')

@section('page_title', 'ফি')
@section('heading', 'ফি')

@section('portal')
    @php
        use App\Models\FeePayment;
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;
        use App\Support\Money;

        $monthsBn = App\Support\BanglaDate::MONTHS;
        $period = function (?string $p) use ($monthsBn) {
            if (! $p) {
                return '';
            }
            if ($p === 'one_time') {
                return 'এককালীন';
            }
            if (str_starts_with($p, 'exam:')) {
                return 'পরীক্ষা';
            }
            [$year, $m] = explode('-', $p);

            return $monthsBn[(int) $m - 1].' '.BanglaNumber::format($year);
        };
        $statuses = ['unpaid' => ['অপরিশোধিত', 'bg-red-50 text-red-800'], 'partial' => ['আংশিক', 'bg-amber-50 text-amber-800'], 'paid' => ['পরিশোধিত', 'bg-green-50 text-green-800'], 'waived' => ['মওকুফ', 'bg-blue-50 text-blue-800']];
    @endphp

    <div class="card-public p-5">
        <p class="text-sm text-gray-500">মোট বকেয়া</p>
        <p class="text-3xl font-bold {{ Money::toPaisa($fees['outstanding_total']) > 0 ? 'text-red-700' : 'text-gray-900' }}" data-testid="outstanding-total">{{ Money::display($fees['outstanding_total']) }}</p>
    </div>

    <section class="mt-6" aria-labelledby="dues-title">
        <h2 id="dues-title" class="text-xl font-semibold text-gray-900">ফি-এর তালিকা</h2>
        @if ($fees['dues']->isEmpty())
            <p class="mt-2 text-gray-500">কোনো ফি নির্ধারিত হয়নি।</p>
        @else
            <div class="mt-3 overflow-x-auto card-public">
                <table class="w-full min-w-[34rem] text-left text-sm">
                    <thead><tr class="border-b border-gray-200 text-gray-500">
                        <th class="p-3 font-medium">সময়</th><th class="p-3 font-medium">খাত</th>
                        <th class="p-3 text-right font-medium">প্রদেয়</th><th class="p-3 text-right font-medium">পরিশোধিত</th>
                        <th class="p-3 text-right font-medium">বকেয়া</th><th class="p-3 font-medium">অবস্থা</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($fees['dues'] as $due)
                            <tr class="border-b border-gray-100" data-due="{{ $due->id }}">
                                <td class="p-3">{{ $period($due->period) }}</td>
                                <td class="p-3 font-medium text-gray-900">{{ $due->head?->name_bn ?: $due->head?->name_en }}</td>
                                <td class="p-3 text-right">{{ Money::display($due->net_amount) }}</td>
                                <td class="p-3 text-right">{{ Money::display($due->paid_amount) }}</td>
                                <td class="p-3 text-right font-semibold">{{ Money::display(Money::fromPaisa($due->outstandingPaisa())) }}</td>
                                <td class="p-3"><span class="rounded-full px-2.5 py-0.5 text-xs {{ $statuses[$due->status][1] ?? '' }}">{{ $statuses[$due->status][0] ?? $due->status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mt-8" aria-labelledby="pay-title">
        <h2 id="pay-title" class="text-xl font-semibold text-gray-900">পরিশোধের তালিকা</h2>
        @if ($fees['payments']->isEmpty())
            <p class="mt-2 text-gray-500">এখনো কোনো পরিশোধ নেই।</p>
        @else
            <div class="mt-3 overflow-x-auto card-public">
                <table class="w-full min-w-[30rem] text-left text-sm">
                    <thead><tr class="border-b border-gray-200 text-gray-500">
                        <th class="p-3 font-medium">রসিদ নং</th><th class="p-3 font-medium">তারিখ</th><th class="p-3 font-medium">মাধ্যম</th>
                        <th class="p-3 text-right font-medium">টাকা</th><th class="p-3"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($fees['payments'] as $payment)
                            <tr class="border-b border-gray-100 {{ $payment->isCancelled() ? 'text-gray-400 line-through' : '' }}">
                                <td class="p-3 font-medium">{{ BanglaNumber::format($payment->receipt_no) }}@if ($payment->isCancelled()) <span class="no-underline">(বাতিল)</span>@endif</td>
                                <td class="p-3">{{ BanglaDate::dateTime($payment->paid_at) }}</td>
                                <td class="p-3">{{ FeePayment::METHOD_LABELS_BN[$payment->method] ?? $payment->method }}</td>
                                <td class="p-3 text-right">{{ Money::display($payment->amount) }}</td>
                                <td class="p-3 text-right"><a href="{{ route('portal.receipt', $payment->id) }}" class="font-medium text-primary-700 hover:underline">রসিদ</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

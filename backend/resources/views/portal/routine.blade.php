@extends('portal.layout')

@section('page_title', 'ক্লাস রুটিন')
@section('heading', 'ক্লাস রুটিন')

@section('head')
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body > header, body > footer, body > a.sr-only, .no-print { display: none !important; }
            .routine-sheet { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
        }
    </style>
@endsection

@section('portal')
    @php
        use App\Support\BanglaNumber;

        $bn = $language === 'bn';
        $dayNames = [
            'saturday' => ['শনিবার', 'Saturday'], 'sunday' => ['রবিবার', 'Sunday'], 'monday' => ['সোমবার', 'Monday'],
            'tuesday' => ['মঙ্গলবার', 'Tuesday'], 'wednesday' => ['বুধবার', 'Wednesday'], 'thursday' => ['বৃহস্পতিবার', 'Thursday'],
            'friday' => ['শুক্রবার', 'Friday'],
        ];
        $num = fn ($value) => $bn ? BanglaNumber::format($value) : (string) $value;
        // Stored as Asia/Dhaka wall-clock "H:i:s".
        $time = fn (?string $value) => $num(substr((string) $value, 0, 5));
        $section = $routine['section'];
        $slots = $routine['slots']->groupBy(fn ($slot) => $slot->period_id.'|'.$slot->day);
    @endphp

    <form method="GET" action="{{ route('portal.routine') }}" class="no-print mb-6 flex flex-wrap items-end gap-3">
        @if ($children->count() > 1)
            <input type="hidden" name="student" value="{{ $student->id }}">
        @endif
        <label class="text-xs text-gray-600">ভাষা
            <select name="language" class="field mt-0.5 !py-1.5">
                <option value="bn" @selected($language === 'bn')>বাংলা</option>
                <option value="en" @selected($language === 'en')>English</option>
            </select>
        </label>
        <button type="submit" class="btn-public-outline !px-4 !py-2 text-sm">প্রয়োগ করুন</button>
        <button type="button" class="btn-public !px-4 !py-2 text-sm" onclick="window.print()">প্রিন্ট</button>
    </form>

    @if ($section === null || $routine['periods']->isEmpty() || $routine['slots']->isEmpty())
        <p class="text-gray-500">এই সেকশনের ক্লাস রুটিন এখনো তৈরি হয়নি।</p>
    @else
        <section class="routine-sheet card-public p-5" aria-label="{{ $bn ? 'ক্লাস রুটিন' : 'Class routine' }}">
            <p class="mb-3 text-sm font-semibold text-gray-800">
                {{ $bn ? ($section->class?->name_bn ?: $section->class?->name) : $section->class?->name }}
                · {{ $bn ? 'সেকশন' : 'Section' }} {{ $section->name }}
                @if ($section->shift)
                    · {{ $bn ? ($section->shift->name_bn ?: $section->shift->name_en) : $section->shift->name_en }}
                @endif
                @if ($routine['academic_year'])
                    · {{ $num($routine['academic_year']->year) }}
                @endif
            </p>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="border border-gray-200 px-3 py-2 font-medium">{{ $bn ? 'পিরিয়ড' : 'Period' }}</th>
                            @foreach ($routine['days'] as $day)
                                <th class="border border-gray-200 px-3 py-2 font-medium">{{ $dayNames[$day][$bn ? 0 : 1] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($routine['periods'] as $period)
                            <tr>
                                <th scope="row" class="border border-gray-200 px-3 py-2 align-top font-medium whitespace-nowrap">
                                    {{ $bn ? $period->name_bn : $period->name_en }}
                                    <span class="block text-xs font-normal text-gray-500">{{ $time($period->start_time) }} – {{ $time($period->end_time) }}</span>
                                </th>
                                @if ($period->is_break)
                                    <td colspan="{{ count($routine['days']) }}" class="border border-gray-200 bg-gray-50 px-3 py-2 text-center text-gray-500">{{ $bn ? $period->name_bn : $period->name_en }}</td>
                                @else
                                    @foreach ($routine['days'] as $day)
                                        @php $cell = $slots->get($period->id.'|'.$day)?->first(); @endphp
                                        <td class="border border-gray-200 px-3 py-2 align-top">
                                            @if ($cell)
                                                <span class="font-semibold text-gray-900">{{ $bn ? ($cell->subject?->name_bn ?: $cell->subject?->name) : $cell->subject?->name }}</span>
                                                @if ($cell->staff)
                                                    <span class="block text-xs text-gray-600">{{ $bn ? ($cell->staff->name_bn ?: $cell->staff->name_en) : ($cell->staff->name_en ?: $cell->staff->name_bn) }}</span>
                                                @endif
                                                @if ($cell->room)
                                                    <span class="block text-xs text-gray-500">{{ $bn ? 'কক্ষ' : 'Room' }}: {{ $cell->room }}</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

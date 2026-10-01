@extends('portal.layout')

@section('page_title', 'ফলাফল')
@section('heading', 'ফলাফল')

@section('portal')
    @php
        use App\Support\BanglaNumber;
        use App\Support\ExamTitle;
    @endphp

    @forelse ($results as $result)
        @if ($loop->first)<ul class="grid gap-3">@endif
        <li class="card-public flex flex-wrap items-center justify-between gap-3 p-4">
            <div>
                <p class="font-semibold text-gray-900">{{ ExamTitle::withYear($result->exam->name_bn ?: $result->exam->name_en, $result->exam->academicYear->year, true) }}</p>
                <p class="text-sm text-gray-500">জিপিএ {{ BanglaNumber::format(number_format((float) $result->gpa, 2, '.', '')) }} · গ্রেড {{ $result->grade }} · {{ $result->is_pass ? 'উত্তীর্ণ' : 'অনুত্তীর্ণ' }}</p>
            </div>
            <a href="{{ route('portal.result', $result->exam_id) }}" class="btn-public-outline !px-4 !py-2 text-sm">মার্কশিট</a>
        </li>
        @if ($loop->last)</ul>@endif
    @empty
        <p class="text-gray-500">এখনো কোনো ফলাফল প্রকাশিত হয়নি।</p>
    @endforelse
@endsection

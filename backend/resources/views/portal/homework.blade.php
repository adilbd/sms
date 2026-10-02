@extends('portal.layout')

@section('page_title', 'হোমওয়ার্ক')
@section('heading', 'হোমওয়ার্ক')

@section('portal')
    @php
        use App\Support\BanglaDate;
        use App\Support\BanglaNumber;

        // Homework arrives nearest due date first; group it by that date.
        $groups = $homework->groupBy(fn ($item) => $item->due_on->toDateString());
    @endphp

    @include('portal._student', ['student' => $student])

    <p class="mt-4 text-sm text-gray-500">যে হোমওয়ার্কের জমার তারিখ আজ বা তার পরে, এবং গত ৩০ দিনে যার মেয়াদ শেষ হয়েছে, তা এখানে দেখানো হয়।</p>

    @forelse ($groups as $dueOn => $items)
        @php $overdue = $dueOn < $today; @endphp
        <section class="mt-6" aria-labelledby="due-{{ $dueOn }}">
            <h2 id="due-{{ $dueOn }}" class="flex flex-wrap items-center gap-2 text-lg font-semibold text-gray-900">
                জমার তারিখ: {{ BanglaDate::date($dueOn) }}
                @if ($overdue)
                    <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800" data-testid="overdue">মেয়াদ শেষ</span>
                @elseif ($dueOn === $today)
                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">আজই জমার শেষ দিন</span>
                @endif
            </h2>

            <div class="mt-3 space-y-3">
                @foreach ($items as $item)
                    <article class="card-public p-5" data-testid="homework-item">
                        <p class="text-sm font-medium text-primary-700">{{ $item->subject?->name_bn ?: $item->subject?->name }}</p>
                        <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $item->title }}</h3>
                        @if ($item->details)
                            <div class="prose prose-sm mt-2 max-w-none text-gray-700">{!! $item->details !!}</div>
                        @endif
                        <p class="mt-3 text-xs text-gray-500">
                            দেওয়া হয়েছে {{ BanglaDate::date($item->assigned_on->toDateString()) }}
                            @if ($item->staff)
                                · {{ $item->staff->name_bn ?: $item->staff->name_en }}
                            @endif
                        </p>
                        @if ($url = $item->signedAttachmentUrl())
                            <a href="{{ $url }}" rel="nofollow" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline">সংযুক্তি: {{ $item->attachment_name ?: 'ফাইল' }}</a>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <p class="mt-6 text-gray-500">এখন কোনো হোমওয়ার্ক নেই।</p>
    @endforelse
@endsection

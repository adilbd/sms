{{--
    The shell of every signed-in portal page: noindex head, the one <h1> (the child's
    'heading' section), the guardian's child switcher and the page tabs. Children fill
    'page_title', 'heading', 'portal' and optionally 'head'. Expects $student and $children.
--}}
@extends('layouts.public')

@section('seo')
    <x-seo :title="trim($__env->yieldContent('page_title'))"
           description="শিক্ষার্থী ও অভিভাবকের ব্যক্তিগত পোর্টাল। এই পাতা সার্চ ইঞ্জিনে দেখানো হয় না।"
           :noindex="true" />
    @yield('head')
@endsection

@section('content')
    @php
        $tabs = [
            'portal.dashboard' => 'ড্যাশবোর্ড',
            'portal.profile' => 'প্রোফাইল',
            'portal.results' => 'ফলাফল',
            'portal.attendance' => 'উপস্থিতি',
            'portal.fees' => 'ফি',
            'portal.exams' => 'পরীক্ষার সূচি',
            'portal.routine' => 'ক্লাস রুটিন',
            'portal.homework' => 'হোমওয়ার্ক',
        ];
    @endphp
    <div class="container-page py-6 sm:py-10">
        <div class="no-print flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">@yield('heading')</h1>
            <form method="POST" action="{{ route('portal.logout') }}">
                @csrf
                <button type="submit" class="btn-public-outline !px-4 !py-2 text-sm">লগআউট</button>
            </form>
        </div>

        @if ($children->count() > 1)
            <nav aria-label="সন্তান বেছে নিন" class="no-print mt-4 flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500">সন্তান:</span>
                @foreach ($children as $child)
                    <a href="{{ request()->url() }}?student={{ $child->id }}"
                       @if ($child->id === $student->id) aria-current="true" @endif
                       class="rounded-full border px-4 py-1.5 text-sm {{ $child->id === $student->id ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                        {{ $child->name_bn ?: $child->name_en }}
                    </a>
                @endforeach
            </nav>
        @endif

        <nav aria-label="পোর্টাল" class="no-print mt-5 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <ul class="flex gap-1 border-b border-gray-200 text-sm font-medium whitespace-nowrap">
                @foreach ($tabs as $route => $label)
                    @php $active = request()->routeIs($route) || ($route === 'portal.results' && request()->routeIs('portal.result')) || ($route === 'portal.fees' && request()->routeIs('portal.receipt')); @endphp
                    <li>
                        <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                           class="inline-block px-4 py-2.5 {{ $active ? 'border-b-2 border-primary-600 text-primary-700' : 'text-gray-600 hover:text-primary-700' }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="mt-6">
            @yield('portal')
        </div>
    </div>
@endsection

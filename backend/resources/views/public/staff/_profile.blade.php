@php $photo = $member->photoUrl(); @endphp
<div class="grid gap-8 sm:grid-cols-3">
    <div class="sm:col-span-1">
        <div class="aspect-square overflow-hidden rounded-xl bg-gray-100">
            @if ($photo)
                <img src="{{ $photo }}" alt="{{ $member->name() }}" loading="lazy" class="h-full w-full object-cover">
            @else
                <div class="flex h-full w-full items-center justify-center text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-20 w-20"><path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd"/></svg>
                </div>
            @endif
        </div>
        @if ($member->shifts->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-1">
                @foreach ($member->shifts as $shiftBadge)
                    <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ $shiftBadge->name_bn }} ({{ $shiftBadge->name_en }})</span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="sm:col-span-2">
        @if ($member->name_en && $member->name_bn)
            <p class="text-lg text-gray-500">{{ $member->name_bn }}</p>
        @endif
        @if ($member->designation)
            <p class="mt-1 text-gray-700">{{ $member->designation }}</p>
        @endif
        @if ($member->subject)
            <p class="text-sm text-gray-500">Subject: {{ $member->subject }}</p>
        @endif
        @if ($member->joining_date)
            <p class="mt-1 text-sm text-gray-500">
                Joined {{ $member->joining_date->format('F Y') }}
                @if ($member->isFormer())
                    &middot; Left {{ $member->leaving_date?->format('F Y') ?: 'unknown' }}
                @endif
            </p>
        @endif
        @if ($member->bio)
            <p class="mt-4 text-gray-700">{{ $member->bio }}</p>
        @endif

        @if ($member->educations->isNotEmpty())
            <h2 class="mt-6 text-lg font-semibold text-gray-900">Education</h2>
            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                @foreach ($member->educations as $education)
                    <li>
                        {{ $education->degree }}
                        @if ($education->board_university) — {{ $education->board_university }} @endif
                        @if ($education->passing_year) ({{ $education->passing_year }}) @endif
                        @if ($education->result) &middot; {{ $education->result }} @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($member->trainings->isNotEmpty())
            <h2 class="mt-6 text-lg font-semibold text-gray-900">Training</h2>
            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                @foreach ($member->trainings as $training)
                    <li>
                        {{ $training->title }}
                        @if ($training->organizer) — {{ $training->organizer }} @endif
                        @if ($training->duration) ({{ $training->duration }}) @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($member->show_contact && ($member->mobile || $member->email))
            <h2 class="mt-6 text-lg font-semibold text-gray-900">Contact</h2>
            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                @if ($member->mobile)<li>Mobile: {{ $member->mobile }}</li>@endif
                @if ($member->email)<li>Email: {{ $member->email }}</li>@endif
            </ul>
        @endif
    </div>
</div>

@if ($shifts->isNotEmpty())
    <div class="mt-6 flex flex-wrap gap-2" role="navigation" aria-label="Filter by shift">
        <a href="{{ route($routeName) }}"
           class="rounded-full px-4 py-1.5 text-sm font-medium {{ ! $shift ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
            All shifts
        </a>
        @foreach ($shifts as $option)
            <a href="{{ route($routeName, ['shift' => $option->slug]) }}"
               class="rounded-full px-4 py-1.5 text-sm font-medium {{ $shift?->id === $option->id ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ $option->name_bn }} ({{ $option->name_en }})
            </a>
        @endforeach
    </div>
@endif

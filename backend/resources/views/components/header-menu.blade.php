{{--
    Renders the public header nav from MenuService::headerTree(): CSS-only dropdowns
    on desktop (Tailwind group-hover/group-focus-within, up to 3 levels) and a
    <details> hamburger on mobile. No <h1> here, so every page keeps exactly one.

    When $items is empty (fresh install, or a test that doesn't seed the menu), the
    six original hardcoded links are rendered instead, so the site never has an
    empty nav.
--}}
@props(['items' => []])

@php
    $current = rtrim(url()->current(), '/');

    $isCurrent = fn (array $node) => ! empty($node['href']) && rtrim($node['href'], '/') === $current;

    $hasActiveDescendant = function (array $node) use (&$hasActiveDescendant, $isCurrent) {
        foreach ($node['children'] ?? [] as $child) {
            if ($isCurrent($child) || $hasActiveDescendant($child)) {
                return true;
            }
        }

        return false;
    };

    $defaultLinks = [
        ['label' => 'Home', 'href' => route('home'), 'open_in_new_tab' => false, 'children' => []],
        ['label' => 'About', 'href' => route('about'), 'open_in_new_tab' => false, 'children' => []],
        ['label' => 'Admissions', 'href' => route('admissions'), 'open_in_new_tab' => false, 'children' => []],
        ['label' => 'News', 'href' => route('news.index'), 'open_in_new_tab' => false, 'children' => []],
        ['label' => 'Events', 'href' => route('events.index'), 'open_in_new_tab' => false, 'children' => []],
        ['label' => 'Contact', 'href' => route('contact'), 'open_in_new_tab' => false, 'children' => []],
    ];

    $menu = $items !== [] ? $items : $defaultLinks;
@endphp

<!-- Desktop navigation -->
<nav aria-label="Main" class="hidden md:block">
    <ul class="flex flex-wrap items-center gap-x-1 text-sm font-medium">
        @foreach ($menu as $level1)
            @php
                $active1 = $isCurrent($level1) || $hasActiveDescendant($level1);
                $hasChildren1 = ! empty($level1['children']);
            @endphp
            <li class="relative {{ $hasChildren1 ? 'group/lvl1' : '' }}">
                @if (! empty($level1['href']))
                    <a href="{{ $level1['href'] }}"
                       @if (! empty($level1['open_in_new_tab'])) target="_blank" rel="noopener" @endif
                       @if ($active1) aria-current="page" @endif
                       class="flex items-center gap-1 px-3 py-2 rounded {{ $active1 ? 'text-primary-700' : 'text-gray-600 hover:text-primary-700' }}">
                        <span>{{ $level1['label'] }}</span>
                        @if ($hasChildren1)<span aria-hidden="true" class="text-[10px]">&#9662;</span>@endif
                    </a>
                @else
                    <button type="button"
                        class="flex items-center gap-1 px-3 py-2 rounded {{ $active1 ? 'text-primary-700' : 'text-gray-600' }} group-hover/lvl1:text-primary-700 group-focus-within/lvl1:text-primary-700">
                        <span>{{ $level1['label'] }}</span>
                        @if ($hasChildren1)<span aria-hidden="true" class="text-[10px]">&#9662;</span>@endif
                    </button>
                @endif

                @if ($hasChildren1)
                    <ul class="invisible opacity-0 pointer-events-none group-hover/lvl1:visible group-hover/lvl1:opacity-100 group-hover/lvl1:pointer-events-auto group-focus-within/lvl1:visible group-focus-within/lvl1:opacity-100 group-focus-within/lvl1:pointer-events-auto transition absolute left-0 top-full pt-2 min-w-[240px] z-40">
                        <div class="bg-white border border-gray-100 shadow-lg rounded-lg py-2">
                            @foreach ($level1['children'] as $level2)
                                @php
                                    $active2 = $isCurrent($level2) || $hasActiveDescendant($level2);
                                    $hasChildren2 = ! empty($level2['children']);
                                @endphp
                                <div class="relative {{ $hasChildren2 ? 'group/lvl2' : '' }}">
                                    @if (! empty($level2['href']))
                                        <a href="{{ $level2['href'] }}"
                                           @if (! empty($level2['open_in_new_tab'])) target="_blank" rel="noopener" @endif
                                           @if ($active2) aria-current="page" @endif
                                           class="flex items-center justify-between gap-2 px-4 py-2 text-sm {{ $active2 ? 'text-primary-700' : 'text-gray-700 hover:bg-gray-50 hover:text-primary-700' }}">
                                            <span>{{ $level2['label'] }}</span>
                                            @if ($hasChildren2)<span aria-hidden="true" class="text-[10px]">&#9656;</span>@endif
                                        </a>
                                    @else
                                        <button type="button"
                                            class="flex w-full items-center justify-between gap-2 px-4 py-2 text-sm {{ $active2 ? 'text-primary-700' : 'text-gray-700' }} group-hover/lvl2:bg-gray-50 group-hover/lvl2:text-primary-700 group-focus-within/lvl2:text-primary-700">
                                            <span>{{ $level2['label'] }}</span>
                                            @if ($hasChildren2)<span aria-hidden="true" class="text-[10px]">&#9656;</span>@endif
                                        </button>
                                    @endif

                                    @if ($hasChildren2)
                                        <ul class="invisible opacity-0 pointer-events-none group-hover/lvl2:visible group-hover/lvl2:opacity-100 group-hover/lvl2:pointer-events-auto group-focus-within/lvl2:visible group-focus-within/lvl2:opacity-100 group-focus-within/lvl2:pointer-events-auto transition absolute left-full top-0 pl-2 min-w-[220px] z-50">
                                            <div class="bg-white border border-gray-100 shadow-lg rounded-lg py-2">
                                                @foreach ($level2['children'] as $level3)
                                                    @php $active3 = $isCurrent($level3); @endphp
                                                    <a href="{{ $level3['href'] }}"
                                                       @if (! empty($level3['open_in_new_tab'])) target="_blank" rel="noopener" @endif
                                                       @if ($active3) aria-current="page" @endif
                                                       class="block px-4 py-2 text-sm {{ $active3 ? 'text-primary-700' : 'text-gray-700 hover:bg-gray-50 hover:text-primary-700' }}">
                                                        {{ $level3['label'] }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>

<!-- Mobile navigation -->
<details class="md:hidden relative">
    <summary class="list-none cursor-pointer select-none px-3 py-2 rounded hover:bg-gray-50 flex items-center gap-2">
        <span aria-hidden="true">&#9776;</span>
        <span class="text-sm font-medium">Menu</span>
    </summary>
    <nav aria-label="Main" class="absolute right-0 mt-2 w-72 max-h-[75vh] overflow-y-auto bg-white border border-gray-100 shadow-lg rounded-lg p-2 z-40">
        <ul class="space-y-1">
            @foreach ($menu as $level1)
                @php $hasChildren1 = ! empty($level1['children']); @endphp
                <li>
                    @if ($hasChildren1)
                        <details>
                            <summary class="cursor-pointer select-none px-3 py-2 rounded text-sm font-medium text-gray-700 hover:bg-gray-50">{{ $level1['label'] }}</summary>
                            <ul class="pl-4 space-y-1">
                                @foreach ($level1['children'] as $level2)
                                    @php $hasChildren2 = ! empty($level2['children']); @endphp
                                    <li>
                                        @if ($hasChildren2)
                                            <details>
                                                <summary class="cursor-pointer select-none px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-50">{{ $level2['label'] }}</summary>
                                                <ul class="pl-4 space-y-1">
                                                    @foreach ($level2['children'] as $level3)
                                                        <li>
                                                            <a href="{{ $level3['href'] }}" @if (! empty($level3['open_in_new_tab'])) target="_blank" rel="noopener" @endif class="block px-3 py-2 rounded text-sm text-gray-600 hover:bg-gray-50 hover:text-primary-700">{{ $level3['label'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @elseif (! empty($level2['href']))
                                            <a href="{{ $level2['href'] }}" @if (! empty($level2['open_in_new_tab'])) target="_blank" rel="noopener" @endif class="block px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-700">{{ $level2['label'] }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @elseif (! empty($level1['href']))
                        <a href="{{ $level1['href'] }}" @if (! empty($level1['open_in_new_tab'])) target="_blank" rel="noopener" @endif class="block px-3 py-2 rounded text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-primary-700">{{ $level1['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
</details>

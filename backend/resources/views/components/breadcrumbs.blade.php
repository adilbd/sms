@props(['items' => []])
{{-- $items: [label => url|null] ; last item is the current page --}}
<nav aria-label="Breadcrumb" class="text-sm text-gray-500">
    <ol class="flex flex-wrap items-center gap-1">
        @foreach ($items as $label => $url)
            <li class="flex items-center gap-1">
                @if ($url && ! $loop->last)
                    <a href="{{ $url }}" class="hover:text-primary-700">{{ $label }}</a>
                    <span aria-hidden="true">/</span>
                @else
                    <span aria-current="page" class="text-gray-700">{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

{{-- Paginasi portal: teks dari lang/id, target sentuh 44px. --}}
@if ($paginator->hasPages())
    @php
        $base = 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    @endphp
    <nav aria-label="{{ __('pondok.portal.pagination_label') }}" class="mt-8 flex flex-wrap items-center justify-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="{{ $base }} text-gray-400" aria-disabled="true">{!! __('pagination.previous') !!}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $base }} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">{!! __('pagination.previous') !!}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="{{ $base }} text-gray-500" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="{{ $base }} bg-primary-600 text-white">
                            <span class="sr-only">{{ __('pondok.portal.pagination_page', ['page' => '']) }}</span>{{ $page }}
                        </span>
                    @else
                        <a href="{{ $url }}" class="{{ $base }} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50"
                           aria-label="{{ __('pondok.portal.pagination_page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $base }} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">{!! __('pagination.next') !!}</a>
        @else
            <span class="{{ $base }} text-gray-400" aria-disabled="true">{!! __('pagination.next') !!}</span>
        @endif
    </nav>
@endif

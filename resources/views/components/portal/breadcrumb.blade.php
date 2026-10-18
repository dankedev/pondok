{{-- Remah navigasi portal. $items: list<array{label: string, url?: string}>. Item tanpa URL = halaman aktif. --}}
@props(['items'])

<nav aria-label="{{ __('pondok.portal.breadcrumb_label') }}" {{ $attributes->class('text-sm') }}>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
        @foreach ($items as $item)
            <li class="flex items-center gap-x-2">
                @unless ($loop->first)
                    <span aria-hidden="true" class="text-gray-400">/</span>
                @endunless
                @if (filled($item['url'] ?? null))
                    <a href="{{ $item['url'] }}" class="rounded-lg text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ $item['label'] }}</a>
                @else
                    <span aria-current="page" class="text-gray-600">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

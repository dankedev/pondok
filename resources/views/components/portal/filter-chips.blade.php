{{-- Chip filter portal. $items: list<array{label: string, url: string, active: bool}>. --}}
@props(['items', 'label'])

<nav aria-label="{{ $label }}" {{ $attributes->class('flex flex-wrap gap-2') }}>
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}"
           @if ($item['active']) aria-current="true" @endif
           @class([
               'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
               'bg-primary-600 text-white' => $item['active'],
               'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $item['active'],
           ])>{{ $item['label'] }}</a>
    @endforeach
</nav>

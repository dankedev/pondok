{{-- Chip filter portal. $items: list<array{label: string, url: string, active: bool}>. --}}
@props(['items', 'label'])

<nav aria-label="{{ $label }}" {{ $attributes->class('flex flex-wrap gap-2') }}>
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}"
           @if ($item['active']) aria-current="true" @endif
           @class([
               'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2',
               'bg-primary-900 text-white' => $item['active'],
               'border border-gray-200 bg-white text-gray-700 hover:border-primary-900 hover:text-primary-950' => ! $item['active'],
           ])>{{ $item['label'] }}</a>
    @endforeach
</nav>

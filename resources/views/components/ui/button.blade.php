@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
])

@php
    $classes = 'inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-full px-6 py-2 text-base font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto '
        .match ($variant) {
            'secondary' => 'border border-gray-300 bg-white text-primary-950 hover:border-primary-900',
            'danger' => 'bg-danger-600 text-white hover:bg-danger-700',
            default => 'bg-primary-900 text-white shadow-button hover:bg-primary-800',
        };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

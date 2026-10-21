@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
])

{{-- `inverse` dan `inverse-secondary`: tombol di atas latar navy (blok Ajakan), fokus `ring-accent-400`. --}}
@php
    $classes = 'inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-full px-6 py-2 text-base font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto '
        .match ($variant) {
            'secondary' => 'border border-gray-300 bg-white text-primary-950 hover:border-primary-900 focus-visible:ring-primary-500',
            'danger' => 'bg-danger-600 text-white hover:bg-danger-700 focus-visible:ring-primary-500',
            'inverse' => 'bg-white text-primary-950 hover:bg-primary-50 focus-visible:ring-accent-400 focus-visible:ring-offset-primary-950',
            'inverse-secondary' => 'border border-primary-300 text-white hover:border-white focus-visible:ring-accent-400 focus-visible:ring-offset-primary-950',
            default => 'bg-brand text-on-brand shadow-button hover:bg-brand-hover focus-visible:ring-primary-500',
        };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

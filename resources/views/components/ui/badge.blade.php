@props(['color' => 'gray'])

@php
    $classes = match ($color) {
        'success' => 'bg-success-50 text-success-700 ring-success-600/20',
        'warning' => 'bg-warning-50 text-warning-800 ring-warning-600/20',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20',
        'info' => 'bg-info-50 text-info-700 ring-info-600/20',
        'primary' => 'bg-primary-50 text-primary-700 ring-primary-600/20',
        'accent' => 'bg-accent-100 text-accent-800 ring-accent-600/20',
        default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class('inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold ring-1 ring-inset '.$classes) }}>{{ $slot }}</span>

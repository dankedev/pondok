@props(['color' => 'info'])

@php
    $classes = match ($color) {
        'success' => 'border-success-200 bg-success-50 text-success-800',
        'warning' => 'border-warning-200 bg-warning-50 text-warning-900',
        'danger' => 'border-danger-200 bg-danger-50 text-danger-800',
        default => 'border-info-200 bg-info-50 text-info-800',
    };
@endphp

<div role="{{ $color === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class('rounded-xl border p-4 text-sm '.$classes) }}>
    {{ $slot }}
</div>

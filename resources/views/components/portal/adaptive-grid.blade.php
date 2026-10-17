@props(['count', 'max'])

{{-- Kolom layar lebar = min(jumlah item, maks.); satu item tanpa grid lebar. Kelas literal agar ikut build Tailwind. --}}
@php
    $classes = match (min($count, $max)) {
        1 => 'grid max-w-xl gap-6',
        2 => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-2',
        3 => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-3',
        default => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-4',
    };
@endphp

<div {{ $attributes->class($classes) }}>{{ $slot }}</div>

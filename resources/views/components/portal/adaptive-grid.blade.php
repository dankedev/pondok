@props(['count', 'max'])

{{-- Kolom layar lebar = min(jumlah item, maks.); satu item tanpa grid lebar. 3–4 kolom memakai `.portal-adaptive-grid`
     (app.css) agar baris sisa rata tengah dengan lebar kartu sama. Kelas literal agar ikut build Tailwind. --}}
@php
    $classes = match (min($count, $max)) {
        1 => 'grid max-w-xl gap-6',
        2 => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-2',
        3 => 'portal-adaptive-grid sm:adaptive-cols-2 lg:adaptive-cols-3',
        default => 'portal-adaptive-grid sm:adaptive-cols-2 lg:adaptive-cols-4',
    };
@endphp

<div {{ $attributes->class($classes) }}>{{ $slot }}</div>

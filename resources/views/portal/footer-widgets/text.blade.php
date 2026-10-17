{{-- Widget Teks: tile logo (logo mobile, lalu desktop) dan nama lembaga bila diminta, lalu teks terbatas yang sudah disanitasi `ParseContentBlocks`. --}}
@php
    $logo = $school['logo_mobile_url'] ?? $school['logo_desktop_url'];
@endphp

@if ($widget['show_logo'])
    @if ($logo)
        {{-- Tile putih agar logo gelap tetap terbaca di latar navy. --}}
        <span class="inline-flex rounded-xl bg-white p-2">
            <img src="{{ $logo }}" alt="" class="h-12 w-auto max-w-48 object-contain">
        </span>
    @endif
    <p class="text-xl font-bold text-white">{{ $school['name'] }}</p>
@endif
@if ($widget['body'] !== null)
    <div class="space-y-3 text-base leading-relaxed text-primary-100 [&_a]:rounded [&_a]:font-semibold [&_a]:text-white [&_a]:underline [&_a]:underline-offset-4 [&_a]:focus-visible:outline-none [&_a]:focus-visible:ring-2 [&_a]:focus-visible:ring-accent-400 [&_strong]:text-white">{!! $widget['body'] !!}</div>
@endif

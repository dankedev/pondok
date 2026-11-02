{{-- Kepala daftar otomatis (dipakai semua varian): judul h2 (heading blok atau judul sumber) dan tautan "Lihat semua" yang selalu tampil. --}}
@php
    $heading = $data['heading'] ?? __('pondok.portal.home.'.match ($data['source']) {
        'achievements' => 'latest_achievements',
        'videos' => 'featured_videos',
        'albums' => 'latest_albums',
        default => 'latest_news',
    });
@endphp
<div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
    <h2 id="{{ $headingId }}" class="text-3xl font-bold tracking-tight lg:text-4xl">{{ $heading }}</h2>
    <a href="{{ $block['url'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => $heading]) }}">{{ __('pondok.portal.home.see_all') }}</a>
</div>

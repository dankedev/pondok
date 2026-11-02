{{-- Daftar otomatis bergaya: jumlah item tetap per sumber (`GetPortalHomeData::FEED_LIMITS`), "Lihat semua" selalu tampil. --}}
@php
    $heading = $data['heading'] ?? __('pondok.portal.home.'.match ($data['source']) {
        'achievements' => 'latest_achievements',
        'videos' => 'featured_videos',
        'albums' => 'latest_albums',
        default => 'latest_news',
    });
@endphp

<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
        <h2 id="{{ $headingId }}" class="text-3xl font-bold tracking-tight lg:text-4xl">{{ $heading }}</h2>
        <a href="{{ $block['url'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => $heading]) }}">{{ __('pondok.portal.home.see_all') }}</a>
    </div>
    <x-portal.adaptive-grid :count="$block['items']->count()" :max="$data['source'] === 'albums' ? 4 : 3">
        @foreach ($block['items'] as $item)
            @if ($data['source'] === 'albums')
                <x-portal.album-card :album="$item" />
            @elseif ($data['source'] === 'videos')
                <div class="space-y-2">
                    <x-portal.video-embed :youtube-id="$item->youtube_id" :title="$item->title" />
                    <h3 class="text-lg font-semibold">{{ $item->title }}</h3>
                </div>
            @else
                <x-portal.post-card :post="$item" />
            @endif
        @endforeach
    </x-portal.adaptive-grid>
</section>

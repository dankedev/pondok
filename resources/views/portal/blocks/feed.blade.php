{{-- Daftar otomatis: jumlah item tetap per sumber (`GetPortalHomeData::FEED_LIMITS`), "Lihat semua" selalu tampil. --}}
@php
    $heading = $data['heading'] ?? __('pondok.portal.home.'.match ($data['source']) {
        'achievements' => 'latest_achievements',
        'videos' => 'featured_videos',
        'albums' => 'latest_albums',
        default => 'latest_news',
    });
@endphp

<section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
            <h2 id="{{ $headingId }}" class="{{ $sectionHeading }}">{{ $heading }}</h2>
            <a href="{{ $block['url'] }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => $heading]) }}">{{ __('pondok.portal.home.see_all') }}</a>
        </div>
        <x-portal.adaptive-grid :count="$block['items']->count()" :max="$data['source'] === 'albums' ? 4 : 3">
            @foreach ($block['items'] as $item)
                @if ($data['source'] === 'albums')
                    <x-portal.album-card :album="$item" />
                @elseif ($data['source'] === 'videos')
                    <div class="space-y-2">
                        <x-portal.video-embed :youtube-id="$item->youtube_id" :title="$item->title" />
                        <h3 class="text-lg font-semibold text-gray-900">{{ $item->title }}</h3>
                    </div>
                @else
                    <x-portal.post-card :post="$item" />
                @endif
            @endforeach
        </x-portal.adaptive-grid>
    </div>
</section>

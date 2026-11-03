{{-- Daftar otomatis sebagai kartu (varian bawaan): jumlah item tetap per sumber (`GetPortalHomeData::FEED_LIMITS`). --}}
<section{{ $pt('feed_list', 'cards') }} class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    @include('portal.theme.styled.feed_list-head')
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

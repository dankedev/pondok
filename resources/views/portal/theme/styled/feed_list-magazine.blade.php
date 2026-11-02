{{-- Daftar otomatis gaya majalah: item pertama sebagai kartu besar di kiri, sisanya baris ringkas di kanan (>= 1024px). --}}
<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    @include('portal.theme.styled.feed_list-head')
    @php
        $lead = $block['items']->first();
        $rest = $block['items']->slice(1);
    @endphp
    <div @class(['grid gap-8', 'lg:grid-cols-3' => $rest->isNotEmpty()])>
        <div @class(['min-w-0', 'lg:col-span-2' => $rest->isNotEmpty()])>
            @if ($data['source'] === 'albums')
                <x-portal.album-card :album="$lead" />
            @elseif ($data['source'] === 'videos')
                <div class="space-y-2">
                    <x-portal.video-embed :youtube-id="$lead->youtube_id" :title="$lead->title" />
                    <h3 class="text-lg font-semibold">{{ $lead->title }}</h3>
                </div>
            @else
                <x-portal.post-card :post="$lead" />
            @endif
        </div>
        @if ($rest->isNotEmpty())
            <ul class="min-w-0 space-y-4">
                @foreach ($rest as $item)
                    @include('portal.theme.styled.feed_list-entry')
                @endforeach
            </ul>
        @endif
    </div>
</section>

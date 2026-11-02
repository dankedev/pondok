{{-- Media hero (dipakai kedua varian): slider album tanpa JavaScript (geser atau panah kiri/kanan setelah kontainer difokus) atau satu gambar. --}}
@if ($block['album'])
    <div role="region" tabindex="0" aria-label="{{ $block['album']['title'] }}"
         class="flex snap-x snap-mandatory gap-4 overflow-x-auto scrollbar-hidden rounded-xl pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current focus-visible:ring-offset-2">
        @foreach ($block['album']['photos'] as $photo)
            <img src="{{ $photo->url() }}"
                 alt="{{ $photo->caption ?: __('pondok.portal.albums.photo_alt', ['album' => $block['album']['title'], 'number' => $loop->iteration]) }}"
                 loading="lazy"
                 class="aspect-video w-5/6 shrink-0 snap-center rounded-xl bg-gray-100 object-cover shadow-card">
        @endforeach
    </div>
@else
    <img src="{{ $block['image'] }}" alt="{{ $data['image_alt'] ?? '' }}"
         class="aspect-video w-full rounded-xl bg-gray-100 object-cover shadow-card">
@endif

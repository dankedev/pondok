{{-- Hero preset Terpusat: teks rata tengah, media lebar di bawah teks di semua lebar layar. Nama Arab tidak dirender otomatis. --}}
<section class="bg-white" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-3xl space-y-6 text-center">
            @if ($block['psbOpen'])
                <x-ui.badge color="accent">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
            @endif
            <hgroup class="space-y-4">
                @if ($data['eyebrow'])
                    <p class="text-base font-semibold text-primary-700 lg:text-lg">{{ $data['eyebrow'] }}</p>
                @endif
                <h1 id="{{ $headingId }}" class="text-balance break-words text-4xl font-bold tracking-tight text-primary-950 sm:text-5xl lg:text-6xl">{{ $data['title'] ?? $school['name'] }}</h1>
                @if ($data['text'])
                    <p class="whitespace-pre-line text-lg leading-relaxed text-gray-600 lg:text-xl">{{ $data['text'] }}</p>
                @endif
            </hgroup>
            @if ($block['buttons'] !== [])
                <div class="flex flex-wrap justify-center gap-3 pt-2">
                    @foreach ($block['buttons'] as $button)
                        <x-ui.button :href="$button['url']" :variant="$loop->first ? 'primary' : 'secondary'">{{ $button['label'] }}</x-ui.button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($block['album'])
            {{-- Slider tanpa JavaScript: geser (sentuh) atau panah kiri/kanan setelah kontainer difokus. --}}
            <div role="region" tabindex="0" aria-label="{{ $block['album']['title'] }}"
                 class="mx-auto mt-12 flex max-w-4xl snap-x snap-mandatory gap-4 overflow-x-auto scrollbar-hidden rounded-xl pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 lg:mt-16">
                @foreach ($block['album']['photos'] as $photo)
                    <img src="{{ $photo->url() }}"
                         alt="{{ $photo->caption ?: __('pondok.portal.albums.photo_alt', ['album' => $block['album']['title'], 'number' => $loop->iteration]) }}"
                         loading="lazy"
                         class="aspect-video w-5/6 shrink-0 snap-center rounded-xl bg-gray-100 object-cover shadow-card">
                @endforeach
            </div>
        @elseif ($block['image'])
            <img src="{{ $block['image'] }}" alt="{{ $data['image_alt'] ?? '' }}"
                 class="mx-auto mt-12 aspect-video w-full max-w-4xl rounded-xl bg-gray-100 object-cover shadow-card lg:mt-16">
        @endif
    </div>
</section>

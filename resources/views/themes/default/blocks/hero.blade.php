{{-- Hero: dua kolom di ≥1024px (teks kiri, media kanan); di layar kecil media di atas teks. Nama Arab tidak dirender otomatis. --}}
@php
    $hasMedia = $block['album'] !== null || $block['image'] !== null;
@endphp

<section class="relative isolate overflow-x-clip bg-white" aria-labelledby="{{ $headingId }}">
    <div aria-hidden="true" class="portal-decor-dots pointer-events-none absolute right-0 top-12 -z-10 hidden h-72 w-56 lg:block xl:w-72"></div>
    <div @class(['mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24', 'grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-12' => $hasMedia])>
        <div @class(['space-y-6', 'max-w-3xl' => ! $hasMedia])>
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
                <div class="flex flex-wrap gap-3 pt-2">
                    @foreach ($block['buttons'] as $button)
                        <x-ui.button :href="$button['url']" :variant="$loop->first ? 'primary' : 'secondary'">{{ $button['label'] }}</x-ui.button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($hasMedia)
            <div class="relative order-first min-w-0 lg:order-none">
                <div aria-hidden="true" class="portal-decor-ring pointer-events-none absolute -bottom-12 -left-12 -z-10 hidden size-40 sm:block lg:size-48"></div>
                @if ($block['album'])
                    {{-- Slider tanpa JavaScript, di dalam kolom: geser (sentuh) atau panah kiri/kanan setelah kontainer difokus. --}}
                    <div role="region" tabindex="0" aria-label="{{ $block['album']['title'] }}"
                         class="flex snap-x snap-mandatory gap-4 overflow-x-auto scrollbar-hidden rounded-xl pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">
                        @foreach ($block['album']['photos'] as $photo)
                            <img src="{{ $photo->url() }}"
                                 alt="{{ $photo->caption ?: __('pondok.portal.albums.photo_alt', ['album' => $block['album']['title'], 'number' => $loop->iteration]) }}"
                                 loading="lazy"
                                 class="aspect-video w-5/6 shrink-0 snap-start rounded-xl bg-gray-100 object-cover shadow-card">
                        @endforeach
                    </div>
                @else
                    <img src="{{ $block['image'] }}" alt="{{ $data['image_alt'] ?? '' }}"
                         class="aspect-video w-full rounded-xl bg-gray-100 object-cover shadow-card">
                @endif
            </div>
        @endif
    </div>
</section>

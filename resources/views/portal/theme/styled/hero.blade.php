{{-- Hero bergaya (tema dengan `style`): latar, padding, dan perataan dari peta gaya pada <section>; teks mewarisi warna latar. Satu h1. --}}
@php
    $hasMedia = $block['album'] !== null || $block['image'] !== null;
    $buttons = $onInverse ? ['inverse', 'inverse-secondary'] : ['primary', 'secondary'];
@endphp

<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div @class(['max-w-3xl space-y-6', 'mx-auto' => $centered])>
        @if ($block['psbOpen'])
            <x-ui.badge color="accent">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
        @endif
        <hgroup class="space-y-4">
            @if ($data['eyebrow'])
                <p class="text-base font-semibold lg:text-lg">{{ $data['eyebrow'] }}</p>
            @endif
            <h1 id="{{ $headingId }}" class="text-balance break-words text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">{{ $data['title'] ?? $school['name'] }}</h1>
            @if ($data['text'])
                <p class="whitespace-pre-line text-lg leading-relaxed lg:text-xl">{{ $data['text'] }}</p>
            @endif
        </hgroup>
        @if ($block['buttons'] !== [])
            <div @class(['flex flex-wrap gap-3 pt-2', 'justify-center' => $centered])>
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$buttons[$loop->first ? 0 : 1]">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>

    @if ($hasMedia)
        <div class="mt-10 w-full max-w-4xl self-center">
            @if ($block['album'])
                {{-- Slider tanpa JavaScript: geser (sentuh) atau panah kiri/kanan setelah kontainer difokus. --}}
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
        </div>
    @endif
</section>

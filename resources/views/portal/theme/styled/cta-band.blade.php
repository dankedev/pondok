{{-- Ajakan pita: teks rata tengah langsung di atas latar node (tombol terbalik bila latar gelap/merek; varian `light/navy/gold` data blok tidak dipakai). --}}
<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-3xl space-y-4 text-center">
        @if ($data['eyebrow'])
            <p class="text-base font-semibold">{{ $data['eyebrow'] }}</p>
        @endif
        <h2 id="{{ $headingId }}" class="text-balance text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] }}</h2>
        @if ($data['text'])
            <p class="whitespace-pre-line text-base leading-relaxed lg:text-lg">{{ $data['text'] }}</p>
        @endif
        @if ($block['buttons'] !== [])
            <div class="flex flex-wrap justify-center gap-3 pt-4">
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$buttons[$loop->first ? 0 : 1]">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>
</section>

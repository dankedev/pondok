{{-- Ajakan bergaya: latar dan perataan dari tema (varian `light/navy/gold` data blok tidak dipakai); tombol terbalik di atas latar gelap/merek. --}}
@php
    $buttons = $onInverse ? ['inverse', 'inverse-secondary'] : ['primary', 'secondary'];
@endphp

<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div @class(['max-w-3xl space-y-4', 'mx-auto' => $centered])>
        @if ($data['eyebrow'])
            <p class="text-base font-semibold">{{ $data['eyebrow'] }}</p>
        @endif
        <h2 id="{{ $headingId }}" class="text-balance text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] }}</h2>
        @if ($data['text'])
            <p class="whitespace-pre-line text-base leading-relaxed lg:text-lg">{{ $data['text'] }}</p>
        @endif
        @if ($block['buttons'] !== [])
            <div @class(['flex flex-wrap gap-3 pt-4', 'justify-center' => $centered])>
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$buttons[$loop->first ? 0 : 1]">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>
</section>

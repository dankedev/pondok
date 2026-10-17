{{-- Ajakan: light = latar band, navy = latar navy teks putih (fokus ring-accent-400), gold = latar emas teks primary-950. --}}
@php
    $variant = in_array($data['variant'], ['navy', 'gold'], true) ? $data['variant'] : 'light';
    $styles = match ($variant) {
        'navy' => ['section' => 'bg-primary-950 text-white', 'eyebrow' => 'text-primary-200', 'heading' => 'text-white', 'text' => 'text-primary-100', 'buttons' => ['inverse', 'inverse-secondary']],
        'gold' => ['section' => 'bg-accent-500 text-primary-950', 'eyebrow' => 'text-primary-950', 'heading' => 'text-primary-950', 'text' => 'text-primary-950', 'buttons' => ['primary', 'secondary']],
        default => ['section' => $band(), 'eyebrow' => 'text-primary-700', 'heading' => 'text-gray-900', 'text' => 'text-gray-700', 'buttons' => ['primary', 'secondary']],
    };
@endphp

<section class="{{ $styles['section'] }} py-16 lg:py-24" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-3xl space-y-4 px-4 text-center sm:px-6">
        @if ($data['eyebrow'])
            <p class="text-base font-semibold {{ $styles['eyebrow'] }}">{{ $data['eyebrow'] }}</p>
        @endif
        <h2 id="{{ $headingId }}" class="text-balance text-3xl font-bold tracking-tight lg:text-4xl {{ $styles['heading'] }}">{{ $data['heading'] }}</h2>
        @if ($data['text'])
            <p class="whitespace-pre-line text-base leading-relaxed lg:text-lg {{ $styles['text'] }}">{{ $data['text'] }}</p>
        @endif
        @if ($block['buttons'] !== [])
            <div class="flex flex-wrap justify-center gap-3 pt-4">
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$styles['buttons'][$loop->first ? 0 : 1]">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>
</section>

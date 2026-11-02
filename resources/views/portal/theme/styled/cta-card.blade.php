{{-- Ajakan kartu: kartu putih di atas latar node; teks dan tombol tetap normal (primer) agar kontras tak bergantung latar. --}}
<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto w-full max-w-3xl space-y-4 rounded-2xl border border-gray-100 bg-white p-8 text-center text-gray-900 shadow-card">
        @if ($data['eyebrow'])
            <p class="text-base font-semibold text-primary-700">{{ $data['eyebrow'] }}</p>
        @endif
        <h2 id="{{ $headingId }}" class="text-balance text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] }}</h2>
        @if ($data['text'])
            <p class="whitespace-pre-line text-base leading-relaxed text-gray-700 lg:text-lg">{{ $data['text'] }}</p>
        @endif
        @if ($block['buttons'] !== [])
            <div class="flex flex-wrap justify-center gap-3 pt-4">
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$loop->first ? 'primary' : 'secondary'">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>
</section>

{{-- Teks + gambar: gambar kanan di ≥1024px, di atas teks di layar kecil. Teks terbatas sudah disanitasi `ParseContentBlocks`. --}}
<section class="{{ $band() }} py-16 lg:py-24" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    <div @class(['mx-auto max-w-6xl px-4 sm:px-6', 'grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-12' => $block['image']])>
        @if ($block['image'])
            <img src="{{ $block['image'] }}" alt="{{ $data['image_alt'] ?? '' }}" loading="lazy"
                 class="aspect-video w-full rounded-xl bg-gray-100 object-cover shadow-card lg:order-last">
        @endif
        <div class="max-w-3xl space-y-4">
            @if ($data['heading'])
                <h2 id="{{ $headingId }}" class="{{ $sectionHeading }}">{{ $data['heading'] }}</h2>
            @endif
            @if ($data['text'])
                <div class="portal-prose">{!! $data['text'] !!}</div>
            @endif
            @foreach ($block['buttons'] as $button)
                <a href="{{ $button['url'] }}" class="{{ $sectionLink }}">
                    {{ $button['label'] }}
                    <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                </a>
            @endforeach
        </div>
    </div>
</section>

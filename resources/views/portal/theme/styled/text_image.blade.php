{{-- Teks + gambar: gambar di atas teks di layar kecil, berdampingan (gambar kiri) di >= 1024px; kelas akar dari `ResolveHomeLayout`. --}}
<section{{ $pt('text_image') }} class="{{ $sectionClass }}" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    <div @class(['grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-12' => $block['image']])>
        @if ($block['image'])
            <img{{ $pt(part: 'media') }} src="{{ $block['image'] }}" alt="{{ $data['image_alt'] ?? '' }}" loading="lazy"
                 class="aspect-video w-full rounded-xl bg-gray-100 object-cover shadow-card">
        @endif
        <div @class(['min-w-0 space-y-4', 'max-w-3xl' => ! $block['image']])>
            @if ($data['heading'])
                <h2{{ $pt(part: 'heading') }} id="{{ $headingId }}" class="text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] }}</h2>
            @endif
            @if ($data['text'])
                <div{{ $pt(part: 'body') }} class="portal-prose">{!! $data['text'] !!}</div>
            @endif
            @foreach ($block['buttons'] as $button)
                <a{{ $pt(part: 'link') }} href="{{ $button['url'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current">
                    {{ $button['label'] }}
                    <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                </a>
            @endforeach
        </div>
    </div>
</section>

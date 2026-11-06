{{-- Kutipan polos: rata tengah tanpa hiasan; warna teks mewarisi latar (`tone`). --}}
<section{{ $pt('quote', 'plain') }} class="{{ $sectionClass }} flex flex-col">
    <figure{{ $pt(part: 'figure') }} class="mx-auto max-w-3xl space-y-4 text-center">
        <blockquote>
            @if ($data['rtl'])
                <p{{ $pt(part: 'text') }} lang="ar" dir="rtl" class="whitespace-pre-line font-arabic text-2xl leading-loose sm:text-3xl">{{ $data['text'] }}</p>
            @else
                <p{{ $pt(part: 'text') }} class="text-balance whitespace-pre-line text-xl leading-relaxed lg:text-2xl">{{ $data['text'] }}</p>
            @endif
        </blockquote>
        @if ($data['translation'])
            <p{{ $pt(part: 'translation') }} class="whitespace-pre-line text-base leading-relaxed lg:text-lg">{{ $data['translation'] }}</p>
        @endif
        @if ($data['source'])
            <figcaption{{ $pt(part: 'source') }} class="text-sm font-semibold">{{ $data['source'] }}</figcaption>
        @endif
    </figure>
</section>

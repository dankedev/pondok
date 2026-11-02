{{-- Kutipan berbingkai: bingkai bergaris warna teks dengan garis hiasan di atas; warna teks mewarisi latar (`tone`). --}}
<section class="{{ $sectionClass }} flex flex-col">
    <figure class="mx-auto max-w-3xl space-y-4 rounded-2xl border border-current/20 p-8 text-center">
        <div aria-hidden="true" class="mx-auto mb-6 h-1 w-12 rounded-full bg-current"></div>
        <blockquote>
            @if ($data['rtl'])
                <p lang="ar" dir="rtl" class="whitespace-pre-line font-arabic text-2xl leading-loose sm:text-3xl">{{ $data['text'] }}</p>
            @else
                <p class="text-balance whitespace-pre-line text-xl leading-relaxed lg:text-2xl">{{ $data['text'] }}</p>
            @endif
        </blockquote>
        @if ($data['translation'])
            <p class="whitespace-pre-line text-base leading-relaxed lg:text-lg">{{ $data['translation'] }}</p>
        @endif
        @if ($data['source'])
            <figcaption class="text-sm font-semibold">{{ $data['source'] }}</figcaption>
        @endif
    </figure>
</section>

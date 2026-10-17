<section class="{{ $band() }} py-16 lg:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <figure class="mx-auto max-w-3xl space-y-4 border-s-4 border-accent-500 ps-6">
            <blockquote>
                @if ($data['rtl'])
                    <p lang="ar" dir="rtl" class="whitespace-pre-line font-arabic text-2xl leading-loose text-primary-950 sm:text-3xl">{{ $data['text'] }}</p>
                @else
                    <p class="whitespace-pre-line text-xl leading-relaxed text-gray-900 lg:text-2xl">{{ $data['text'] }}</p>
                @endif
            </blockquote>
            @if ($data['translation'])
                <p class="whitespace-pre-line text-base leading-relaxed text-gray-700 lg:text-lg">{{ $data['translation'] }}</p>
            @endif
            @if ($data['source'])
                <figcaption class="text-sm font-semibold text-gray-600">{{ $data['source'] }}</figcaption>
            @endif
        </figure>
    </div>
</section>

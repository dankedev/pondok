<section class="{{ $band() }} py-16 lg:py-24" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        @if ($data['heading'])
            <h2 id="{{ $headingId }}" class="mb-8 {{ $sectionHeading }} lg:mb-10">{{ $data['heading'] }}</h2>
        @endif
        <x-portal.adaptive-grid :count="count($data['items'])" :max="4">
            @foreach ($data['items'] as $item)
                <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-card">
                    <p class="text-lg font-semibold text-gray-900">{{ $item['title'] }}</p>
                    @if ($item['text'])
                        <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700">{{ $item['text'] }}</p>
                    @endif
                </div>
            @endforeach
        </x-portal.adaptive-grid>
    </div>
</section>

{{-- Keunggulan sebagai kartu: kolom mengikuti jumlah butir (`x-portal.adaptive-grid`); kartu putih agar kontras tak bergantung latar. --}}
<section class="{{ $sectionClass }} flex flex-col" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    @if ($data['heading'])
        <h2 id="{{ $headingId }}" class="mb-8 text-3xl font-bold tracking-tight lg:mb-10 lg:text-4xl">{{ $data['heading'] }}</h2>
    @endif
    <x-portal.adaptive-grid :count="count($data['items'])" :max="4">
        @foreach ($data['items'] as $item)
            <div class="min-w-0 rounded-xl border border-gray-100 bg-white p-6 text-gray-900 shadow-card">
                <p class="break-words text-lg font-semibold">{{ $item['title'] }}</p>
                @if ($item['text'])
                    <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700">{{ $item['text'] }}</p>
                @endif
            </div>
        @endforeach
    </x-portal.adaptive-grid>
</section>

{{-- Keunggulan bergaya: <section> adalah grid (kolom dan gap dari peta gaya); kartu putih agar kontras tak bergantung latar. --}}
<section class="{{ $sectionClass }} grid" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    @if ($data['heading'])
        <h2 id="{{ $headingId }}" class="col-span-full text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] }}</h2>
    @endif
    @foreach ($data['items'] as $item)
        <div class="min-w-0 rounded-xl border border-gray-100 bg-white p-6 text-gray-900 shadow-card">
            <p class="break-words text-lg font-semibold">{{ $item['title'] }}</p>
            @if ($item['text'])
                <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700">{{ $item['text'] }}</p>
            @endif
        </div>
    @endforeach
</section>

{{-- Keunggulan preset Terpusat: daftar bergaris tanpa kartu; satu kolom di layar kecil, dua kolom di ≥1024px. --}}
@php
    $twoColumns = count($data['items']) > 1;
@endphp

<section class="{{ $band() }} py-16 lg:py-24" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        @if ($data['heading'])
            <h2 id="{{ $headingId }}" class="mb-8 text-center {{ $sectionHeading }} lg:mb-10">{{ $data['heading'] }}</h2>
        @endif
        <ul @class([
            'mx-auto max-w-4xl divide-y divide-gray-200 border-y border-gray-200',
            'lg:grid lg:grid-cols-2 lg:gap-x-12 lg:divide-y-0 lg:border-b-0' => $twoColumns,
        ])>
            @foreach ($data['items'] as $item)
                <li @class(['py-6', 'lg:border-b lg:border-gray-200' => $twoColumns])>
                    <p class="text-lg font-semibold text-gray-900">{{ $item['title'] }}</p>
                    @if ($item['text'])
                        <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700">{{ $item['text'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- Keunggulan bernomor: daftar berurut tanpa kartu, nomor dalam lingkaran warna merek; warna teks mewarisi latar. --}}
<section{{ $pt('feature_grid', 'numbered') }} class="{{ $sectionClass }} flex flex-col" @if ($data['heading']) aria-labelledby="{{ $headingId }}" @endif>
    @if ($data['heading'])
        <h2{{ $pt(part: 'heading') }} id="{{ $headingId }}" class="mb-8 text-3xl font-bold tracking-tight lg:mb-10 lg:text-4xl">{{ $data['heading'] }}</h2>
    @endif
    <ol class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($data['items'] as $item)
            <li{{ $pt(part: 'item') }} class="flex min-w-0 gap-4">
                <span{{ $pt(part: 'number') }} aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand text-base font-bold text-on-brand">{{ $loop->iteration }}</span>
                <div class="min-w-0">
                    <p{{ $pt(part: 'item-title') }} class="break-words text-lg font-semibold">{{ $item['title'] }}</p>
                    @if ($item['text'])
                        <p{{ $pt(part: 'item-text') }} class="mt-2 whitespace-pre-line text-base leading-relaxed">{{ $item['text'] }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>

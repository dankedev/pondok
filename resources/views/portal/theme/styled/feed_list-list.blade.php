{{-- Daftar otomatis sebagai baris ringkas tanpa gambar: satu kolom, judul bertautan dan keterangan. --}}
<section{{ $pt('feed_list', 'list') }} class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    @include('portal.theme.styled.feed_list-head')
    <ul{{ $pt(part: 'list') }} class="max-w-3xl space-y-4">
        @foreach ($block['items'] as $item)
            @include('portal.theme.styled.feed_list-entry')
        @endforeach
    </ul>
</section>

{{-- Daftar otomatis sebagai baris ringkas tanpa gambar: satu kolom, judul bertautan dan keterangan. --}}
<section class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    @include('portal.theme.styled.feed_list-head')
    <ul class="max-w-3xl space-y-4">
        @foreach ($block['items'] as $item)
            @include('portal.theme.styled.feed_list-entry')
        @endforeach
    </ul>
</section>

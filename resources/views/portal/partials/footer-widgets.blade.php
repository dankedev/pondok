{{--
    Grid widget footer (F046): kolom `lg` = jumlah widget tampil (kelas literal agar ikut build Tailwind), `md` maks. 2.
    Dipakai footer inti dan komponen `footer_widgets` tema. Butuh `$footer` (`GetPortalFooter`) dan `$gridClass`
    (kelas wadah tanpa kolom; kolom ditambahkan di sini). Widget tampil di atas latar navy.
--}}
@php
    $widgets = $footer['widgets'];
    // 4 widget baru 4 kolom mulai xl (1280px); di 1024px cukup 2 kolom agar alamat dan email tidak terpotong.
    $columns = [1 => 'lg:grid-cols-1', 2 => 'md:grid-cols-2 lg:grid-cols-2', 3 => 'md:grid-cols-2 lg:grid-cols-3', 4 => 'md:grid-cols-2 xl:grid-cols-4'];
    $linkClass = \App\Actions\Konten\GetPortalFooter::LINK_CLASS;
@endphp

<div class="{{ $gridClass }} {{ $columns[count($widgets)] }}">
    @foreach ($widgets as $widget)
        <div class="min-w-0 space-y-3">
            @if ($widget['heading'] !== null)
                <h2 id="footer-widget-{{ $loop->iteration }}" class="text-base font-semibold text-white">{{ $widget['heading'] }}</h2>
            @endif
            @include('portal.footer-widgets.'.$widget['type'], ['widget' => $widget, 'headingId' => 'footer-widget-'.$loop->iteration, 'linkClass' => $linkClass])
        </div>
    @endforeach
</div>

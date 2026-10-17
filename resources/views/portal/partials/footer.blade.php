{{--
    Footer portal (F046): widget `portal.footer_widgets` dari `GetPortalFooter` (hanya yang tampil dan berisi), lalu hak cipta.
    Penuh: grid widget, kolom `lg` = jumlah widget tampil (kelas literal agar ikut build Tailwind), `md` maks. 2.
    Ringkas ($compact, route `psb.*` dan 404): nama, tautan widget Tautan pertama (`compactLinks`) sebaris, hak cipta.
--}}
@php
    $compact ??= false;
    $footer = app(\App\Actions\Konten\GetPortalFooter::class)->handle($school);
    $widgets = $footer['widgets'];
    // 4 widget baru 4 kolom mulai xl (1280px); di 1024px cukup 2 kolom agar alamat dan email tidak terpotong.
    $columns = [1 => 'lg:grid-cols-1', 2 => 'md:grid-cols-2 lg:grid-cols-2', 3 => 'md:grid-cols-2 lg:grid-cols-3', 4 => 'md:grid-cols-2 xl:grid-cols-4'];
    // Footer navy (satu-satunya section gelap): teks putih/primary-100/primary-200, fokus emas (11.96:1 terhadap navy).
    $linkClass = 'inline-flex min-h-11 items-center rounded-lg text-base text-primary-100 underline-offset-4 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-400';
    $rights = __('pondok.portal.footer.rights', ['year' => now()->year, 'name' => $school['name']]);
@endphp

<footer class="border-t-4 border-accent-500 bg-primary-950">
    @if ($compact)
        <div class="mx-auto max-w-6xl space-y-3 px-4 py-8 sm:px-6">
            <p class="text-base font-bold text-white">{{ $school['name'] }}</p>
            @if ($footer['compactLinks'] !== [])
                <ul class="flex flex-wrap gap-x-6" aria-label="{{ __('pondok.portal.footer.links') }}">
                    @foreach ($footer['compactLinks'] as $link)
                        <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
            <p class="text-sm text-primary-200">{{ $rights }}</p>
        </div>
    @else
        @if ($widgets !== [])
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:py-16 {{ $columns[count($widgets)] }}">
                @foreach ($widgets as $widget)
                    <div class="min-w-0 space-y-3">
                        @if ($widget['heading'] !== null)
                            <h2 id="footer-widget-{{ $loop->iteration }}" class="text-base font-semibold text-white">{{ $widget['heading'] }}</h2>
                        @endif
                        @include('portal.footer-widgets.'.$widget['type'], ['widget' => $widget, 'headingId' => 'footer-widget-'.$loop->iteration, 'linkClass' => $linkClass])
                    </div>
                @endforeach
            </div>
        @endif
        <div @class(['border-t border-white/10' => $widgets !== []])>
            <p class="mx-auto max-w-6xl px-4 py-5 text-sm text-primary-200 sm:px-6">{{ $rights }}</p>
        </div>
    @endif
</footer>

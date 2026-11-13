{{--
    Footer inti portal (F046); juga cadangan saat tema aktif tidak punya `footer` atau gagal dirender (F102).
    Widget `portal.footer_widgets` dari `GetPortalFooter` (hanya yang tampil dan berisi), lalu hak cipta.
    Butuh `$school` dan `$footer` (dihitung sekali oleh layout publik).
    Ringkas ($compact, route `psb.*` dan 404): nama, tautan widget Tautan pertama (`compactLinks`) sebaris, hak cipta.
--}}
@php
    $compact ??= false;
    $widgets = $footer['widgets'];
    // Footer navy (satu-satunya section gelap): teks putih/primary-100/primary-200, fokus emas (11.96:1 terhadap navy).
    $linkClass = \App\Actions\Konten\GetPortalFooter::LINK_CLASS;
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
            @include('portal.partials.footer-widgets', ['gridClass' => 'mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:py-16'])
        @endif
        <div @class(['border-t border-white/10' => $widgets !== []])>
            <p class="mx-auto max-w-6xl px-4 py-5 text-sm text-primary-200 sm:px-6">{{ $rights }}</p>
        </div>
    @endif
</footer>

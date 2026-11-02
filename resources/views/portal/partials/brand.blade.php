{{--
    Merek header portal: logo desktop untuk >= md, logo mobile untuk < md; logo cetak tidak dipakai di header.
    Dipakai header inti dan komponen `brand` tema. Tautan, bukan heading: `h1` halaman ada di isi.
    Butuh `$school`.
--}}
@php
    $desktopLogo = $school['logo_desktop_url'];
    $mobileLogo = $school['logo_mobile_url'];
@endphp

<a href="{{ route('portal.home') }}"
   class="flex min-h-11 min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
    @if ($desktopLogo && $mobileLogo)
        <picture class="block min-w-0 md:max-w-64">
            <source media="(min-width: 768px)" srcset="{{ $desktopLogo }}">
            <img src="{{ $mobileLogo }}" alt="{{ $school['name'] }}" class="h-10 w-auto max-w-full object-contain md:h-14">
        </picture>
        <span class="sr-only">{{ $school['name'] }}</span>
    @elseif ($desktopLogo)
        <img src="{{ $desktopLogo }}" alt="{{ $school['name'] }}" class="h-10 w-auto min-w-0 max-w-full object-contain md:h-14 md:max-w-64">
        <span class="sr-only">{{ $school['name'] }}</span>
    @elseif ($mobileLogo)
        <span class="shrink-0 md:hidden"><img src="{{ $mobileLogo }}" alt="{{ $school['name'] }}" class="h-10 w-auto max-w-full object-contain"></span>
        <span class="hidden truncate text-lg font-bold text-brand-text md:inline">{{ $school['name'] }}</span>
    @elseif ($school['short_name'])
        <span class="hidden truncate text-lg font-bold text-brand-text md:inline">{{ $school['name'] }}</span>
        <span class="truncate text-lg font-bold text-brand-text md:hidden">{{ $school['short_name'] }}</span>
    @else
        <span class="truncate text-lg font-bold text-brand-text">{{ $school['name'] }}</span>
    @endif
</a>

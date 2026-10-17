{{--
    Layout publik bersama: portal F017 (stateless, di-cache publik) dan halaman PSB F010 (Livewire, bersesi).
    Jangan memuat aset Livewire, token CSRF, sesi, auth, atau $errors di sini: Livewire menyuntik
    asetnya sendiri (auto-inject) hanya pada respons yang merender komponen.
--}}
@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'ogType' => 'website',
    'ogImage' => null,
    'noindex' => false,
    // true: halaman mengatur kontainernya sendiri (portal); false: kontainer sempit untuk form PSB.
    'bare' => false,
    // true: footer ringkas (nama, tautan widget Tautan pertama, hak cipta). Null: otomatis untuk route `psb.*`.
    'compactFooter' => null,
])

@php
    $school = app(\App\Actions\Konten\GetSchoolProfile::class)->handle();
    // Menu utama dan tombol header dihitung sekali untuk kedua nav; footer menghitung widgetnya sendiri (`GetPortalFooter`).
    $navigation = app(\App\Actions\Konten\GetPortalNavigation::class)->handle($school);
    $pageTitle = filled($title)
        ? __('pondok.portal.meta.title', ['title' => $title, 'name' => $school['name']])
        : ($school['tagline'] !== null ? __('pondok.portal.meta.home_title', ['name' => $school['name'], 'tagline' => $school['tagline']]) : $school['name']);
    // Tanpa teks bawaan aplikasi: deskripsi halaman, lalu slogan, selain itu tanpa tag.
    $metaDescription = filled($description) ? $description : $school['tagline'];
    $canonicalUrl = $canonical ?? route('portal.home');
    // Logo cetak (bukan logo desktop) sebagai gambar bagikan bawaan.
    $imageUrl = $ogImage ?? $school['logo_url'];
    $desktopLogo = $school['logo_desktop_url'];
    $mobileLogo = $school['logo_mobile_url'];
    $compactFooter ??= request()->routeIs('psb.*');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @if (filled($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    @if ($noindex)
        <meta name="robots" content="noindex">
    @endif
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $school['name'] }}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $title ?? $school['name'] }}">
    @if (filled($metaDescription))
        <meta property="og:description" content="{{ $metaDescription }}">
    @endif
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if ($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    @if ($school['favicon_url'])
        <link rel="icon" type="image/png" href="{{ $school['favicon_url'] }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}">
    @endif
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased">
    <a href="#konten"
       class="sr-only rounded-full bg-white px-5 py-3 text-base font-semibold text-primary-700 shadow-card focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        {{ __('pondok.portal.nav.skip_to_content') }}
    </a>

    <header class="border-b border-gray-100 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('portal.home') }}"
               class="flex min-h-11 min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                {{-- Logo desktop untuk >= md, logo mobile untuk < md; logo cetak tidak dipakai di header. --}}
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
                    <span class="hidden truncate text-lg font-bold text-primary-950 md:inline">{{ $school['name'] }}</span>
                @elseif ($school['short_name'])
                    <span class="hidden truncate text-lg font-bold text-primary-950 md:inline">{{ $school['name'] }}</span>
                    <span class="truncate text-lg font-bold text-primary-950 md:hidden">{{ $school['short_name'] }}</span>
                @else
                    <span class="truncate text-lg font-bold text-primary-950">{{ $school['name'] }}</span>
                @endif
            </a>

            {{-- Menu bisa sampai 8 item + Masuk/Daftar: nav penuh mulai lg, menu lipat di bawahnya. --}}
            <nav class="hidden lg:flex lg:items-center lg:gap-1" aria-label="{{ __('pondok.portal.nav.label') }}">
                @include('portal.partials.nav-links', ['layout' => 'desktop', 'navigation' => $navigation])
            </nav>

            <details class="group relative lg:hidden">
                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-full border border-gray-200 px-4 text-base font-semibold text-primary-950 hover:border-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    <x-heroicon-o-bars-3 class="size-6" aria-hidden="true" />
                    {{ __('pondok.portal.nav.menu') }}
                </summary>
                <nav class="absolute right-0 z-10 mt-2 flex w-64 flex-col gap-1 rounded-xl border border-gray-100 bg-white p-2 shadow-card-lg" aria-label="{{ __('pondok.portal.nav.mobile_label') }}">
                    @include('portal.partials.nav-links', ['layout' => 'mobile', 'navigation' => $navigation])
                </nav>
            </details>
        </div>
    </header>

    {{-- Halaman non-bare (form PSB) di atas abu muda sampai footer; halaman portal bare di atas body putih. --}}
    <main id="konten" @class(['flex-1', 'bg-gray-50' => ! $bare])>
        @if ($bare)
            {{ $slot }}
        @else
            <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:py-14">
                {{ $slot }}
            </div>
        @endif
    </main>

    @include('portal.partials.footer', ['school' => $school, 'compact' => $compactFooter])
</body>
</html>

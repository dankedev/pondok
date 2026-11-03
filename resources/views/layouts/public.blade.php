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
    // true/false memaksa; null = otomatis: tema aktif (F102) hanya di route `portal.*` (selain sitemap/robots) dan `psb.info`.
    'themed' => null,
])

@php
    $school = app(\App\Actions\Konten\GetSchoolProfile::class)->handle();
    // Menu utama, tombol header, dan widget footer dihitung sekali; dipakai chrome inti maupun chrome tema.
    $navigation = app(\App\Actions\Konten\GetPortalNavigation::class)->handle($school);
    $footer = app(\App\Actions\Konten\GetPortalFooter::class)->handle($school);
    $pageTitle = filled($title)
        ? __('pondok.portal.meta.title', ['title' => $title, 'name' => $school['name']])
        : ($school['tagline'] !== null ? __('pondok.portal.meta.home_title', ['name' => $school['name'], 'tagline' => $school['tagline']]) : $school['name']);
    // Tanpa teks bawaan aplikasi: deskripsi halaman, lalu slogan, selain itu tanpa tag.
    $metaDescription = filled($description) ? $description : $school['tagline'];
    $canonicalUrl = $canonical ?? route('portal.home');
    // Logo cetak (bukan logo desktop) sebagai gambar bagikan bawaan.
    $imageUrl = $ogImage ?? $school['logo_url'];
    $compactFooter ??= request()->routeIs('psb.*');
    // Satu pemeriksaan untuk seluruh chrome bertema: halaman bersesi, 404, verifikasi, dan portal wali tetap chrome inti.
    $themed ??= (request()->routeIs('portal.*') && ! request()->routeIs('portal.sitemap', 'portal.robots')) || request()->routeIs('psb.info');
    $activeTheme = $themed ? app(\App\Actions\Konten\ResolveActiveTheme::class)->handle() : null;
    // Tema admin (F070): tanpa tema `css` dan `font_aliases` null, jadi keluaran sama dengan bawaan.
    $theme = app(\App\Actions\Konten\GetPortalTheme::class)->handle();
    // Tema ber-CSS (F105) yang lolos pemeriksaan runtime: stylesheet RELATIF (HTML di-cache publik) dan `data-pt-theme`; selain itu kosong tanpa spasi (byte tak berubah).
    $themeCss = isset($activeTheme['assets']['css'], $activeTheme['asset_hash']);
    $themeCssLink = $themeCss
        ? new \Illuminate\Support\HtmlString("\n    <link rel=\"stylesheet\" href=\"".e(route('theme-asset', ['slug' => $activeTheme['slug'], 'hash12' => $activeTheme['asset_hash'], 'path' => 'theme.css'], absolute: false)).'">')
        : '';
    $themeBodyAttribute = $themeCss ? new \Illuminate\Support\HtmlString(' data-pt-theme="'.e($activeTheme['slug']).'"') : '';
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
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}">
    @endif
    @fonts($theme['font_aliases'])
    @vite(['resources/css/app.css'])
    @if ($theme['css'])
        {{-- Hanya hex hasil BuildColorScale dan nilai peta server (GetPortalTheme); harus setelah @vite agar menang atas @layer theme. --}}
        <style data-portal-theme>{!! $theme['css'] !!}</style>
    @endif{{ $themeCssLink }}
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased"{{ $themeBodyAttribute }}>
    <a href="#konten"
       class="sr-only rounded-full bg-white px-5 py-3 text-base font-semibold text-primary-700 shadow-card focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        {{ __('pondok.portal.nav.skip_to_content') }}
    </a>

    @if ($activeTheme['header'] ?? null)
        <x-portal.theme-node :node="$activeTheme['header']" area="header" :root="true" :school="$school" :navigation="$navigation" />
    @else
        @include('portal.partials.header')
    @endif

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

    @if ($activeTheme['footer'] ?? null)
        <x-portal.theme-node :node="$activeTheme['footer']" area="footer" :root="true" :school="$school" :footer="$footer" :compact="$compactFooter" />
    @else
        @include('portal.partials.footer', ['compact' => $compactFooter])
    @endif
</body>
</html>

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
])

@php
    $school = app(\App\Actions\Konten\GetSchoolProfile::class)->handle();
    $pageTitle = filled($title)
        ? __('pondok.portal.meta.title', ['title' => $title, 'name' => $school['name']])
        : ($school['tagline'] !== null ? __('pondok.portal.meta.home_title', ['name' => $school['name'], 'tagline' => $school['tagline']]) : $school['name']);
    $metaDescription = filled($description)
        ? $description
        : ($school['tagline'] ?? __('pondok.portal.meta.default_description', ['name' => $school['name']]));
    $canonicalUrl = $canonical ?? route('portal.home');
    $imageUrl = $ogImage ?? $school['logo_url'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    @if ($noindex)
        <meta name="robots" content="noindex">
    @endif
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $school['name'] }}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $title ?? $school['name'] }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if ($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-gray-50 font-sans text-gray-900 antialiased">
    <a href="#konten"
       class="sr-only rounded-lg bg-white px-4 py-3 text-base font-semibold text-primary-700 focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        {{ __('pondok.portal.nav.skip_to_content') }}
    </a>

    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('portal.home') }}"
               class="flex min-h-11 min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                @if ($school['logo_url'])
                    <img src="{{ $school['logo_url'] }}" alt="{{ $school['name'] }}" class="size-10 shrink-0 rounded-lg object-contain">
                @endif
                <span class="truncate text-base font-semibold text-gray-900">{{ $school['name'] }}</span>
            </a>

            <nav class="hidden md:flex md:items-center md:gap-1" aria-label="{{ __('pondok.portal.nav.label') }}">
                @include('portal.partials.nav-links', ['layout' => 'desktop'])
            </nav>

            <details class="group relative md:hidden">
                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-3 text-base font-semibold text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    <x-heroicon-o-bars-3 class="size-6" aria-hidden="true" />
                    {{ __('pondok.portal.nav.menu') }}
                </summary>
                <nav class="absolute right-0 z-10 mt-2 flex w-64 flex-col gap-1 rounded-lg border border-gray-200 bg-white p-2 shadow-sm" aria-label="{{ __('pondok.portal.nav.mobile_label') }}">
                    @include('portal.partials.nav-links', ['layout' => 'mobile'])
                </nav>
            </details>
        </div>
    </header>

    <main id="konten" class="flex-1">
        @if ($bare)
            {{ $slot }}
        @else
            <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
                {{ $slot }}
            </div>
        @endif
    </main>

    @include('portal.partials.footer', ['school' => $school])
</body>
</html>

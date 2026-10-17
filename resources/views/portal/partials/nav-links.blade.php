{{--
    Tautan navigasi utama portal; dirender dua kali (desktop dan menu seluler) dari `GetPortalNavigation`. Tanpa query.
    Urutan: item menu utama (grup = submenu satu tingkat), Masuk, lalu tombol header.
--}}
@php
    $loginUrl = \Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : null;
    $isMobile = $layout === 'mobile';
    $linkClass = $isMobile
        ? 'flex min-h-11 items-center rounded-full px-4 text-base font-semibold'
        : 'inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold';
    $focusClass = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $stateClass = fn (bool $active): string => $active ? 'bg-primary-50 text-primary-900' : 'text-gray-700 hover:bg-gray-50 hover:text-primary-950';
    $button = $navigation['headerButton'];
@endphp

@foreach ($navigation['primary'] as $item)
    @if (isset($item['children']))
        @if ($isMobile)
            {{-- Seluler: judul grup sebagai teks, anak berindentasi di bawahnya. --}}
            <div class="flex flex-col gap-1">
                <p class="px-4 pt-2 text-sm font-semibold text-gray-500">{{ $item['label'] }}</p>
                @foreach ($item['children'] as $child)
                    <a href="{{ $child['url'] }}"
                       @if ($child['active']) aria-current="page" @endif
                       class="{{ $linkClass }} ml-3 {{ $focusClass }} {{ $stateClass($child['active']) }}">{{ $child['label'] }}</a>
                @endforeach
            </div>
        @else
            <details class="group/menu relative">
                <summary @if ($item['active']) aria-current="true" @endif
                         class="{{ $linkClass }} cursor-pointer list-none gap-1 {{ $focusClass }} {{ $stateClass($item['active']) }}">{{ $item['label'] }}<x-heroicon-o-chevron-down class="size-4 transition-transform group-open/menu:rotate-180" aria-hidden="true" /></summary>
                <div class="absolute left-0 z-10 mt-2 flex w-56 flex-col gap-1 rounded-xl border border-gray-100 bg-white p-2 shadow-card-lg">
                    @foreach ($item['children'] as $child)
                        <a href="{{ $child['url'] }}"
                           @if ($child['active']) aria-current="page" @endif
                           class="flex min-h-11 items-center rounded-full px-4 text-sm font-semibold {{ $focusClass }} {{ $stateClass($child['active']) }}">{{ $child['label'] }}</a>
                    @endforeach
                </div>
            </details>
        @endif
    @else
        <a href="{{ $item['url'] }}"
           @if ($item['active']) aria-current="page" @endif
           class="{{ $linkClass }} {{ $focusClass }} {{ $stateClass($item['active']) }}">{{ $item['label'] }}</a>
    @endif
@endforeach

@if ($loginUrl)
    <a href="{{ $loginUrl }}"
       class="{{ $linkClass }} gap-2 {{ $focusClass }} {{ $stateClass(false) }}">
        <x-heroicon-o-arrow-right-end-on-rectangle class="size-5" aria-hidden="true" />
        {{ __('pondok.portal.nav.login') }}
    </a>
@endif

@if ($button)
    <a href="{{ $button['url'] }}"
       @if ($button['active']) aria-current="page" @endif
       class="{{ $linkClass }} justify-center bg-primary-900 px-5 text-white shadow-button hover:bg-primary-800 {{ $focusClass }} focus-visible:ring-offset-2 {{ $isMobile ? 'mt-1' : 'ml-2' }}">{{ $button['label'] }}</a>
@endif

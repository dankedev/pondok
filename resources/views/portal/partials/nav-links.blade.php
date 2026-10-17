{{-- Tautan navigasi utama portal; dirender dua kali (desktop dan menu seluler) dari `GetPortalNavigation`. Tanpa query. --}}
@php
    $items = $navigation['items'];
    $registerUrl = $navigation['registerUrl'];
    $loginUrl = \Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : null;
    $linkClass = $layout === 'mobile'
        ? 'flex min-h-11 items-center rounded-full px-4 text-base font-semibold'
        : 'inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold';
@endphp

@foreach ($items as $item)
    <a href="{{ $item['url'] }}"
       @if ($item['active']) aria-current="page" @endif
       @class([
           $linkClass,
           'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
           'bg-primary-50 text-primary-900' => $item['active'],
           'text-gray-700 hover:bg-gray-50 hover:text-primary-950' => ! $item['active'],
       ])>{{ $item['label'] }}</a>
@endforeach

@if ($loginUrl)
    <a href="{{ $loginUrl }}"
       class="{{ $linkClass }} gap-2 text-gray-700 hover:bg-gray-50 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <x-heroicon-o-arrow-right-end-on-rectangle class="size-5" aria-hidden="true" />
        {{ __('pondok.portal.nav.login') }}
    </a>
@endif

@if ($registerUrl)
    <a href="{{ $registerUrl }}"
       @if (request()->routeIs('psb.register')) aria-current="page" @endif
       class="{{ $linkClass }} justify-center bg-primary-900 px-5 text-white shadow-button hover:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 {{ $layout === 'mobile' ? 'mt-1' : 'ml-2' }}">
        {{ __('pondok.portal.nav.register') }}
    </a>
@endif

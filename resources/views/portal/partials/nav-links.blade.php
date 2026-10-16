{{-- Tautan navigasi utama portal; dirender dua kali (desktop dan menu seluler). Tanpa query. --}}
@php
    $contentEnabled = \App\Models\Setting::moduleEnabled('konten');
    $currentPage = request()->route('page');
    $items = [
        ['label' => __('pondok.portal.nav.home'), 'url' => route('portal.home'), 'active' => request()->routeIs('portal.home')],
    ];

    if ($contentEnabled) {
        $items[] = ['label' => __('pondok.portal.nav.profile'), 'url' => route('portal.pages.show', 'profil'), 'active' => request()->routeIs('portal.pages.show') && in_array($currentPage, ['profil', 'visi-misi'], true)];
        $items[] = ['label' => __('pondok.portal.nav.news'), 'url' => route('portal.posts.index'), 'active' => request()->routeIs('portal.posts.*')];
        $items[] = ['label' => __('pondok.portal.nav.gallery'), 'url' => route('portal.albums.index'), 'active' => request()->routeIs('portal.albums.*')];
        $items[] = ['label' => __('pondok.portal.nav.videos'), 'url' => route('portal.videos.index'), 'active' => request()->routeIs('portal.videos.*')];
        $items[] = ['label' => __('pondok.portal.nav.contact'), 'url' => route('portal.pages.show', 'kontak'), 'active' => request()->routeIs('portal.pages.show') && $currentPage === 'kontak'];
    }

    $registerUrl = \App\Models\Setting::moduleEnabled('psb') && \Illuminate\Support\Facades\Route::has('psb.register') ? route('psb.register') : null;
    $loginUrl = \Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : null;
    $linkClass = $layout === 'mobile'
        ? 'flex min-h-11 items-center rounded-lg px-3 text-base font-semibold'
        : 'inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold';
@endphp

@foreach ($items as $item)
    <a href="{{ $item['url'] }}"
       @if ($item['active']) aria-current="page" @endif
       @class([
           $linkClass,
           'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
           'bg-primary-50 text-primary-700' => $item['active'],
           'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! $item['active'],
       ])>{{ $item['label'] }}</a>
@endforeach

@if ($loginUrl)
    <a href="{{ $loginUrl }}"
       class="{{ $linkClass }} gap-2 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <x-heroicon-o-arrow-right-end-on-rectangle class="size-5" aria-hidden="true" />
        {{ __('pondok.portal.nav.login') }}
    </a>
@endif

@if ($registerUrl)
    <a href="{{ $registerUrl }}"
       @if (request()->routeIs('psb.register')) aria-current="page" @endif
       class="{{ $linkClass }} justify-center bg-primary-600 text-white hover:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 {{ $layout === 'mobile' ? 'mt-1' : 'ml-2' }}">
        {{ __('pondok.portal.nav.register') }}
    </a>
@endif

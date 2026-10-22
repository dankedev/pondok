{{-- Bilah akun portal wali: nama wali, tautan Beranda, dan tombol Keluar (POST). --}}
<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-gray-200 pb-4">
    <p class="min-w-0 truncate text-sm text-gray-600">{{ __('pondok.portal_wali.signed_in_as', ['name' => auth()->user()->name]) }}</p>
    <div class="flex items-center gap-2">
        <a href="{{ route('portal-wali.home') }}"
           @if (request()->routeIs('portal-wali.home')) aria-current="page" @endif
           class="inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-semibold text-primary-700 hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <x-heroicon-o-home class="size-5" aria-hidden="true" />
            {{ __('pondok.portal_wali.home') }}
        </a>
        <form method="POST" action="{{ route('portal-wali.logout') }}">
            @csrf
            <button type="submit"
                    class="inline-flex min-h-11 items-center gap-2 rounded-full border border-gray-300 bg-white px-4 text-sm font-semibold text-primary-950 hover:border-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                <x-heroicon-o-arrow-right-start-on-rectangle class="size-5" aria-hidden="true" />
                {{ __('pondok.portal_wali.logout') }}
            </button>
        </form>
    </div>
</div>

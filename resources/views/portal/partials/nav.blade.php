{{--
    Menu utama portal: nav penuh mulai xl (di 1024px label terlipat), menu lipat di bawahnya; dua nav
    ber-`aria-label` berbeda. Dipakai header inti dan komponen `nav_menu` tema. Butuh `$navigation`.
--}}
<nav{{ \App\Actions\Konten\ThemeComponents::hooks('nav_menu', null, 'desktop') }} class="hidden xl:flex xl:items-center xl:gap-1" aria-label="{{ __('pondok.portal.nav.label') }}">
    @include('portal.partials.nav-links', ['layout' => 'desktop', 'navigation' => $navigation])
</nav>

<details{{ \App\Actions\Konten\ThemeComponents::hooks('nav_menu', null, 'mobile') }} class="group relative xl:hidden">
    <summary{{ \App\Actions\Konten\ThemeComponents::hooks(part: 'toggle') }} class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-full border border-gray-200 px-4 text-base font-semibold text-primary-950 hover:border-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <x-heroicon-o-bars-3 class="size-6" aria-hidden="true" />
        {{ __('pondok.portal.nav.menu') }}
    </summary>
    <nav class="absolute right-0 z-10 mt-2 flex w-64 flex-col gap-1 rounded-xl border border-gray-100 bg-white p-2 shadow-card-lg" aria-label="{{ __('pondok.portal.nav.mobile_label') }}">
        @include('portal.partials.nav-links', ['layout' => 'mobile', 'navigation' => $navigation])
    </nav>
</details>

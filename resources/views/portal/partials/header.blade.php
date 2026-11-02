{{--
    Header inti portal; juga cadangan saat tema aktif tidak punya `header` atau gagal dirender (F102).
    Butuh `$school` dan `$navigation` (dihitung sekali oleh layout publik).
--}}
<header class="border-b border-gray-100 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        @include('portal.partials.brand')

        @include('portal.partials.nav')
    </div>
</header>

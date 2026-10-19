{{--
    Isi halaman error (404 portal dan 4xx/5xx global): kode, judul, penjelasan, dan aksi.
    Tanpa query database atau sesi agar tetap bisa dipakai halaman 5xx.
--}}
@props([
    'code',
    'title',
    'body',
])

<section class="mx-auto flex max-w-xl flex-col items-center px-4 py-16 text-center sm:px-6 lg:py-24">
    <p class="text-sm font-semibold text-primary-700">{{ __('pondok.portal.errors.code_label', ['code' => $code]) }}</p>
    <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">{{ $title }}</h1>
    <p class="mt-3 text-base text-gray-600">{{ $body }}</p>

    @if ($slot->isNotEmpty())
        <div class="mt-8 flex w-full flex-col items-center justify-center gap-3 sm:w-auto sm:flex-row">
            {{ $slot }}
        </div>
    @endif
</section>

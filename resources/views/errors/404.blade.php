{{-- 404 seluruh aplikasi: layout publik, noindex, tanpa detail teknis. Tidak bergantung pada sesi. --}}
<x-layouts::public :title="__('pondok.portal.errors.not_found_title')" :noindex="true" :bare="true" :compact-footer="true">
    <div class="mx-auto max-w-2xl px-4 py-16 text-center sm:px-6 lg:py-24">
        <x-heroicon-o-map class="mx-auto size-12 text-gray-400" aria-hidden="true" />
        <h1 class="mt-4 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ __('pondok.portal.errors.not_found_title') }}</h1>
        <p class="mt-2 text-base text-gray-600">{{ __('pondok.portal.errors.not_found_body') }}</p>
        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <x-ui.button :href="route('portal.home')">{{ __('pondok.portal.errors.back_home') }}</x-ui.button>
            @if (\App\Models\Setting::moduleEnabled('konten'))
                <x-ui.button variant="secondary" :href="route('portal.posts.index')">{{ __('pondok.portal.errors.read_news') }}</x-ui.button>
            @endif
        </div>
    </div>
</x-layouts::public>

{{-- 404 seluruh aplikasi: layout publik, noindex, tanpa detail teknis. Tidak bergantung pada sesi. --}}
<x-layouts::public :title="__('pondok.portal.errors.not_found_title')" :noindex="true" :bare="true" :compact-footer="true">
    <x-error-message code="404" :title="__('pondok.portal.errors.not_found_title')" :body="__('pondok.portal.errors.not_found_body')">
        <x-ui.button :href="route('portal.home')">{{ __('pondok.portal.errors.back_home') }}</x-ui.button>
        @if (\App\Models\Setting::moduleEnabled('konten'))
            <x-ui.button variant="secondary" :href="route('portal.posts.index')">{{ __('pondok.portal.errors.read_news') }}</x-ui.button>
        @endif
    </x-error-message>
</x-layouts::public>

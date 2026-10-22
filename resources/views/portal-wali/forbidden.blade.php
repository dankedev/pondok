{{-- 403 portal wali (EnsurePortalWaliUser): akun login bukan wali aktif. --}}
<x-layouts::public :title="__('pondok.portal_wali.forbidden_title')" :noindex="true" :bare="true">
    <x-error-message code="403" :title="__('pondok.portal_wali.forbidden_title')" :body="__('pondok.portal_wali.errors.not_wali')">
        <x-ui.button :href="route('filament.admin.auth.login')">{{ __('pondok.portal_wali.admin_login') }}</x-ui.button>
    </x-error-message>
</x-layouts::public>

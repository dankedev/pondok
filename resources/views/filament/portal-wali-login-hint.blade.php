{{-- F082: arahkan wali yang tersesat ke login staf menuju portal wali (hanya saat modulnya aktif). --}}
@if (\App\Enums\Module::PortalWali->isEnabled())
    <p class="text-center text-sm text-gray-600">
        {{ __('pondok.portal_wali.admin_login_hint') }}
        <x-filament::link :href="route('portal-wali.login')">{{ __('pondok.portal_wali.admin_login_link') }}</x-filament::link>
    </p>
@endif

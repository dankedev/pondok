{{-- F055: peringatan persisten untuk super-admin dan bendahara yang belum mengaktifkan 2FA. --}}
@if (auth()->user()?->shouldBeWarnedToEnableMultiFactor())
    <div class="pt-4 lg:pt-6">
        <x-filament::callout
            color="warning"
            icon="heroicon-o-shield-exclamation"
            :heading="__('pondok.user.security.mfa_warning_title')"
            :description="__('pondok.user.security.mfa_warning_body')"
        >
            <x-slot name="footer">
                <x-filament::link :href="filament()->getProfileUrl()" color="warning" icon="heroicon-o-arrow-right" icon-position="after">
                    {{ __('pondok.user.security.mfa_warning_link') }}
                </x-filament::link>
            </x-slot>
        </x-filament::callout>
    </div>
@endif

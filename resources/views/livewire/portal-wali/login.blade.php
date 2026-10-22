<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.login_title') }}</h1>
        <p class="text-base text-gray-600">{{ __('pondok.portal_wali.login_intro') }}</p>
    </div>

    @if (session('status'))
        <x-ui.alert color="success">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="authenticate" class="flex flex-col gap-4">
            <x-ui.field :label="__('pondok.portal_wali.email')" for="email">
                <input type="email" id="email" wire:model="email" autocomplete="username" inputmode="email" required class="ui-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-ui.field>

            <x-ui.field :label="__('pondok.portal_wali.password')" for="password">
                <input type="password" id="password" wire:model="password" autocomplete="current-password" required class="ui-input" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            </x-ui.field>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="authenticate">
                    <span wire:loading.remove wire:target="authenticate">{{ __('pondok.portal_wali.login_submit') }}</span>
                    <span wire:loading wire:target="authenticate">{{ __('pondok.portal_wali.processing') }}</span>
                </x-ui.button>
                <a href="{{ route('portal-wali.password.request') }}" class="inline-flex min-h-11 items-center rounded-lg text-base font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 self-center">{{ __('pondok.portal_wali.forgot_link') }}</a>
            </div>
        </form>
    </x-ui.card>

    <p class="text-sm text-gray-600">
        {{ __('pondok.portal_wali.staff_hint') }}
        <a href="{{ route('filament.admin.auth.login') }}" class="rounded-lg font-semibold text-primary-700 underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ __('pondok.portal_wali.admin_login') }}</a>
    </p>
</div>

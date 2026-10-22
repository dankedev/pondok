<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.reset_title') }}</h1>
        <p class="text-base text-gray-600">{{ __('pondok.portal_wali.reset_intro') }}</p>
    </div>

    <x-ui.card>
        <form wire:submit="resetPassword" class="flex flex-col gap-4">
            <x-ui.field :label="__('pondok.portal_wali.email')" for="email">
                <input type="email" id="email" wire:model="email" autocomplete="username" inputmode="email" required class="ui-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-ui.field>

            <x-ui.field :label="__('pondok.portal_wali.new_password')" for="password">
                <input type="password" id="password" wire:model="password" autocomplete="new-password" required class="ui-input" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            </x-ui.field>

            <x-ui.field :label="__('pondok.portal_wali.new_password_confirmation')" for="password_confirmation">
                <input type="password" id="password_confirmation" wire:model="password_confirmation" autocomplete="new-password" required class="ui-input">
            </x-ui.field>

            <div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resetPassword">
                    <span wire:loading.remove wire:target="resetPassword">{{ __('pondok.portal_wali.reset_submit') }}</span>
                    <span wire:loading wire:target="resetPassword">{{ __('pondok.portal_wali.processing') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>

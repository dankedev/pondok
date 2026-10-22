<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.forgot_title') }}</h1>
        <p class="text-base text-gray-600">{{ __('pondok.portal_wali.forgot_intro') }}</p>
    </div>

    @if ($status)
        <x-ui.alert color="success">{{ $status }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="send" class="flex flex-col gap-4">
            <x-ui.field :label="__('pondok.portal_wali.email')" for="email">
                <input type="email" id="email" wire:model="email" autocomplete="email" inputmode="email" required class="ui-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-ui.field>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">{{ __('pondok.portal_wali.forgot_submit') }}</span>
                    <span wire:loading wire:target="send">{{ __('pondok.portal_wali.processing') }}</span>
                </x-ui.button>
                <a href="{{ route('portal-wali.login') }}" class="inline-flex min-h-11 items-center rounded-lg text-base font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 self-center">{{ __('pondok.portal_wali.back_to_login') }}</a>
            </div>
        </form>
    </x-ui.card>
</div>

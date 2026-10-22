{{-- Prompt verifikasi email portal wali (F079). --}}
<x-layouts::public :title="__('pondok.portal_wali.verify_title')" :noindex="true">
    <div class="flex flex-col gap-6">
        @include('portal-wali.partials.account-bar')

        <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.verify_title') }}</h1>

        @if (session('status'))
            <x-ui.alert color="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <form method="POST" action="{{ route('portal-wali.verification.send') }}" class="flex flex-col gap-4">
                @csrf
                <div class="flex items-start gap-3">
                    <x-heroicon-o-envelope class="size-6 shrink-0 text-gray-500" aria-hidden="true" />
                    <p class="text-base text-gray-700">
                        {{ __('pondok.portal_wali.verify_intro', ['email' => auth()->user()->email, 'minutes' => config('auth.verification.expire', 60)]) }}
                    </p>
                </div>
                <div>
                    <x-ui.button type="submit">{{ __('pondok.portal_wali.verify_resend') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts::public>

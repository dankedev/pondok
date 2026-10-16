@php
    $schoolName = \App\Models\Setting::get('school.name', config('app.name'));
    $tagline = \App\Models\Setting::get('school.tagline');
    $isLoggedIn = auth()->check();
    $panelUrl = $isLoggedIn ? route('filament.admin.pages.dashboard') : route('filament.admin.auth.login');
    $psbAvailable = \App\Models\Setting::moduleEnabled('psb') && \Illuminate\Support\Facades\Route::has('psb.register');
    $letterVerifyAvailable = \App\Models\Setting::moduleEnabled('dokumen') && \Illuminate\Support\Facades\Route::has('dokumen.letters.verify-form');
@endphp

@component('layouts.public')
    @slot('nav')
        <a href="{{ $panelUrl }}"
           class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-primary-700 hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <x-heroicon-o-arrow-right-end-on-rectangle class="size-5" aria-hidden="true" />
            {{ $isLoggedIn ? __('pondok.home.open_panel') : __('pondok.home.login') }}
        </a>
    @endslot

    <div class="space-y-6">
        <div class="py-4">
            <h1 class="text-3xl font-semibold text-gray-900">{{ $schoolName }}</h1>
            @if (filled($tagline))
                <p class="mt-2 text-base text-gray-600">{{ $tagline }}</p>
            @endif
        </div>

        <x-ui.card :title="__('pondok.home.panel_title')">
            <p class="text-base text-gray-600">{{ __('pondok.home.panel_body') }}</p>
            <div class="mt-4">
                <x-ui.button :href="$panelUrl">
                    {{ $isLoggedIn ? __('pondok.home.panel_open') : __('pondok.home.panel_login') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        @if ($psbAvailable)
            <x-ui.card :title="__('pondok.home.psb_title')">
                <p class="text-base text-gray-600">{{ __('pondok.home.psb_body') }}</p>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <x-ui.button variant="secondary" :href="route('psb.register')">{{ __('pondok.home.psb_register') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('psb.status')">{{ __('pondok.home.psb_status') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($letterVerifyAvailable)
            <p class="text-sm">
                <a href="{{ route('dokumen.letters.verify-form') }}"
                   class="font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    {{ __('pondok.home.letter_verify') }}
                </a>
            </p>
        @endif
    </div>
@endcomponent

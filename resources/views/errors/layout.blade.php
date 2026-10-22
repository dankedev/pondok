{{--
    Kerangka halaman error global (4xx/5xx). Sengaja tanpa database, sesi, setting, atau komponen yang
    membaca profil lembaga: halaman 5xx harus tetap tampil saat database atau sesi bermasalah.
    Nama aplikasi diambil dari config (`APP_NAME`), bukan dari setting.
--}}
@php
    $code = $exception->getStatusCode();
    $group = $code >= 500 ? '5xx' : '4xx';
    $message = trans()->has("pondok.http_errors.{$code}") ? "pondok.http_errors.{$code}" : "pondok.http_errors.{$group}";
    $title = __("{$message}.title");
    $body = __("{$message}.body");
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased">
    <header class="border-b border-gray-100 bg-white">
        <div class="mx-auto flex max-w-6xl items-center px-4 py-4 sm:px-6">
            <a href="{{ url('/') }}" class="rounded-lg text-base font-bold text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ config('app.name') }}</a>
        </div>
    </header>

    <main class="flex flex-1 items-center">
        <div class="w-full">
            <x-error-message :code="$code" :title="$title" :body="$body">
                @if ($code === 419)
                    <x-ui.button :href="url()->current()">{{ __('pondok.http_errors.reload') }}</x-ui.button>
                @elseif ($code !== 503)
                    @if ($portalWaliUrl ?? null)
                        <x-ui.button :href="$portalWaliUrl">{{ __('pondok.portal_wali.open_portal') }}</x-ui.button>
                        <x-ui.button variant="secondary" :href="url('/')">{{ __('pondok.portal.errors.back_home') }}</x-ui.button>
                    @else
                        <x-ui.button :href="url('/')">{{ __('pondok.portal.errors.back_home') }}</x-ui.button>
                    @endif
                @endif
            </x-error-message>
        </div>
    </main>

    <footer class="border-t border-gray-100">
        <p class="mx-auto max-w-6xl px-4 py-6 text-center text-sm text-gray-600 sm:px-6">© {{ now()->year }} {{ config('app.name') }}</p>
    </footer>
</body>
</html>

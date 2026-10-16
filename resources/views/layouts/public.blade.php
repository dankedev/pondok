@php
    $schoolName = \App\Models\Setting::get('school.name', config('app.name'));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · '.$schoolName : $schoolName }}</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <span class="text-base font-semibold text-gray-900">{{ $schoolName }}</span>
            @isset($nav)
                <nav aria-label="{{ __('pondok.home.nav_label') }}" class="flex items-center gap-2">
                    {{ $nav }}
                </nav>
            @endisset
        </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>

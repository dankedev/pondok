{{-- Halaman tamu (login, lupa sandi) cukup logo; nama produk dan lembaga hanya di header panel. --}}
@if (filament()->auth()->check())
    <div class="flex items-center gap-2.5 whitespace-nowrap">
        <img src="{{ asset('logo.svg') }}" alt="" width="36" height="36" class="size-9 shrink-0" style="width: 2.25rem; height: 2.25rem;">
        <div class="min-w-0">
            <div class="text-base font-bold text-gray-950 dark:text-white" style="line-height: 1.1;">{{ __('pondok.brand.product') }}</div>
            <div class="mt-0.5 max-w-[12rem] truncate text-xs text-gray-500 dark:text-gray-400" style="line-height: 1.1;">{{ $institution }}</div>
        </div>
    </div>
@else
    <img src="{{ asset('logo.svg') }}" alt="{{ __('pondok.brand.product') }}" width="48" height="48" style="width: 3rem; height: 3rem;">
@endif

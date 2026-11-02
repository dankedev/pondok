<x-filament-panels::page>
    @if ($catalogError)
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="warning"
            :heading="__('pondok.theme_catalog.error_heading')"
        >
            {{ $catalogError }}
        </x-filament::section>
    @endif

    {{ $this->table }}

    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('pondok.theme_catalog.cache_note') }}</p>
</x-filament-panels::page>

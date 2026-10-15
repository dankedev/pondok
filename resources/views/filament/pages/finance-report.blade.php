<x-filament-panels::page>
    <div
        wire:loading.flex
        wire:target="data"
        role="status"
        style="align-items: center; gap: 0.5rem;"
    >
        <x-filament::loading-indicator style="height: 1.25rem; width: 1.25rem;" />
        <span>{{ __('pondok.finance_report.loading') }}</span>
    </div>

    {{ $this->content }}
</x-filament-panels::page>

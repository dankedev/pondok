<li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
    <span class="text-gray-950 dark:text-white">
        {{ __('pondok.theme_editor.contrast.pair', ['fg' => $row['fg'], 'bg' => $row['bg']]) }}
        <span class="text-gray-600 dark:text-gray-400">({{ __('pondok.theme_editor.contrast.size.'.$row['size']) }})</span>
    </span>
    <span class="flex items-center gap-2">
        <span class="tabular-nums">{{ __('pondok.theme_editor.contrast_ratio', ['ratio' => number_format($row['ratio'], 1, ',', '')]) }}</span>
        <x-filament::badge
            :color="$row['passes'] ? 'success' : 'danger'"
            :icon="$row['passes'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'"
        >
            {{ __($row['passes'] ? 'pondok.theme_editor.contrast.pass' : 'pondok.theme_editor.contrast.fail') }}
        </x-filament::badge>
    </span>
</li>

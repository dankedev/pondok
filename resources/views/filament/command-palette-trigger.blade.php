<button
    type="button"
    x-data
    x-on:click="$dispatch('open-command-palette')"
    class="flex items-center gap-2 rounded-full px-3 py-1.5 text-sm text-gray-500 ring-1 ring-gray-950/10 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-400 dark:ring-white/20 dark:hover:bg-white/5"
    aria-label="{{ __('pondok.command_palette.trigger') }}"
>
    <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-5" />
    <span class="hidden sm:inline">{{ __('pondok.command_palette.trigger') }}</span>
    <kbd class="hidden rounded border border-gray-300 px-1.5 text-xs text-gray-500 sm:inline dark:border-white/20 dark:text-gray-400">Ctrl K</kbd>
</button>

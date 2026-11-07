<x-filament-panels::page>
    @php($localThemes = $this->localThemes())

    <x-filament::section :heading="__('pondok.theme_editor.pick.heading')" :description="__('pondok.theme_editor.pick.description')">
        @if ($localThemes === [])
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('pondok.theme_editor.pick.empty') }}</p>
        @else
            <label class="grid max-w-md gap-1 text-sm font-medium text-gray-950 dark:text-white">
                {{ __('pondok.theme_editor.pick.label') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedSlug">
                        <option value="">{{ __('pondok.theme_editor.pick.placeholder') }}</option>
                        @foreach ($localThemes as $slug => $name)
                            <option value="{{ $slug }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
    </x-filament::section>

    @if ($selectedSlug)
        @if ($this->isActiveTheme())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('pondok.theme_editor.active_note') }}</p>
        @endif

        {{ $this->content }}

        @if ($rows = $this->contrastRows())
            <x-filament::section :heading="__('pondok.theme_editor.contrast.heading')" :description="__('pondok.theme_editor.contrast.description')">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
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
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('pondok.theme_editor.preview.heading')" :description="__('pondok.theme_editor.preview.description')">
            <ul class="flex flex-wrap gap-3">
                @foreach ($this->previewLinks() as $link)
                    <li>
                        <x-filament::link :href="$link['url']" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square">
                            {{ $link['label'] }}
                        </x-filament::link>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>

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
            @php([$failing, $passing] = $this->splitContrastRows($rows))
            <x-filament::section :heading="__('pondok.theme_editor.contrast.heading')" :description="__('pondok.theme_editor.contrast.description')">
                <p class="mb-2 text-sm font-medium {{ $failing === [] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ __('pondok.theme_editor.contrast.summary', ['pass' => count($passing), 'total' => count($rows)]) }}
                </p>

                @if ($failing !== [])
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($failing as $row)
                            @include('filament.pages.site.theme-editor-contrast-row', ['row' => $row])
                        @endforeach
                    </ul>
                @endif

                @if ($passing !== [])
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-400">{{ __('pondok.theme_editor.contrast.show_passing', ['count' => count($passing)]) }}</summary>
                        <ul class="mt-1 divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($passing as $row)
                                @include('filament.pages.site.theme-editor-contrast-row', ['row' => $row])
                            @endforeach
                        </ul>
                    </details>
                @endif
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

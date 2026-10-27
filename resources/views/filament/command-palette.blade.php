<div
    x-data="{
        open: false,
        show() {
            this.open = true
            this.$nextTick(() => this.$refs.input.focus())
        },
        items() {
            return [...this.$refs.list.querySelectorAll('[data-palette-item]')]
        },
        move(delta) {
            const items = this.items()
            const next = items.indexOf(document.activeElement) + delta

            if (next < 0) {
                this.$refs.input.focus()
            } else if (items[next]) {
                items[next].focus()
            }
        },
    }"
    x-on:open-command-palette.window="show()"
    x-on:keydown.ctrl.k.window.prevent="show()"
    x-on:keydown.meta.k.window.prevent="show()"
    x-on:keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="{{ __('pondok.command_palette.title') }}">
        <div class="absolute inset-0 bg-gray-950/50" x-on:click="open = false"></div>

        <div
            x-show="open"
            x-trap.noscroll="open"
            class="relative flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <div class="flex items-center gap-3 border-b border-gray-200 px-4 dark:border-white/10">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-5 shrink-0 text-gray-400" />
                <input
                    x-ref="input"
                    type="text"
                    wire:model.live.debounce.200ms="search"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    autocomplete="off"
                    maxlength="100"
                    placeholder="{{ __('pondok.command_palette.placeholder') }}"
                    class="w-full border-0 bg-transparent py-3 text-sm text-gray-950 placeholder:text-gray-400 focus:ring-0 dark:text-white"
                />
                <kbd class="shrink-0 rounded border border-gray-300 px-1.5 text-xs text-gray-500 dark:border-white/20 dark:text-gray-400">Esc</kbd>
            </div>

            <div
                x-ref="list"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                class="overflow-y-auto p-2"
            >
                @php($query = trim($search))

                @if (mb_strlen($query) === 1)
                    <p class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('pondok.command_palette.min_length') }}</p>
                @elseif ($categories->isEmpty())
                    <p class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('pondok.command_palette.empty') }}</p>
                @else
                    @foreach ($categories as $name => $results)
                        <div class="py-1" wire:key="palette-{{ $loop->index }}">
                            <h3 class="px-3 pb-1 pt-2 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $name }}</h3>

                            @foreach ($results as $result)
                                <a
                                    href="{{ $result->url }}"
                                    data-palette-item
                                    class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm text-gray-950 hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-white dark:hover:bg-white/5 dark:focus:bg-white/5"
                                >
                                    <span class="truncate">{{ $result->title }}</span>
                                    @if ($result->details)
                                        <span class="shrink-0 truncate text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $result->details) }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

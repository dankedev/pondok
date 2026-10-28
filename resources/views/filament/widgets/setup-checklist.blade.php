@php
    $steps = collect($progress['steps'])->values()->map(fn (array $step, int $index): array => [...$step, 'number' => $index + 1]);
    $pending = $steps->where('done', false)->values();
    $next = $pending->first();
    $later = $pending->slice(1);
    $completed = $steps->where('done', true);
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('pondok.dashboard.setup.heading')"
        :description="__('pondok.dashboard.setup.description')"
        icon="heroicon-o-rocket-launch"
    >
        {{-- Progres --}}
        <div class="flex items-end justify-between gap-4">
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ __('pondok.dashboard.setup.progress', ['done' => $progress['done_count'], 'total' => $progress['total']]) }}
            </p>
            <p class="text-2xl font-semibold leading-none tabular-nums text-gray-950 dark:text-white">
                {{ __('pondok.dashboard.setup.percent', ['percent' => $progress['percent']]) }}
            </p>
        </div>

        <div
            class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
            role="progressbar"
            aria-label="{{ __('pondok.dashboard.setup.progress_label') }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $progress['percent'] }}"
        >
            <div class="h-full rounded-full bg-primary-600 dark:bg-primary-500" style="width: {{ $progress['percent'] }}%"></div>
        </div>

        {{-- Langkah berikutnya: satu aksi utama --}}
        @if ($next)
            <div class="mt-6 rounded-xl bg-primary-50 p-4 ring-1 ring-primary-600/20 sm:p-5 dark:bg-primary-400/10 dark:ring-primary-400/30">
                <p class="text-sm font-medium text-primary-700 dark:text-primary-400">
                    {{ __('pondok.dashboard.setup.next') }} · {{ __('pondok.dashboard.setup.step_number', ['number' => $next['number']]) }}
                </p>
                <h3 class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                    {{ __("pondok.dashboard.setup.steps.{$next['key']}.title") }}
                </h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ __("pondok.dashboard.setup.steps.{$next['key']}.description") }}
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-3">
                    @if ($next['url'])
                        <x-filament::button :href="$next['url']" tag="a" icon="heroicon-o-arrow-right" icon-position="after">
                            {{ __("pondok.dashboard.setup.steps.{$next['key']}.action") }}
                        </x-filament::button>
                    @else
                        <x-filament::badge color="gray" icon="heroicon-o-server">
                            {{ __("pondok.dashboard.setup.steps.{$next['key']}.server") }}
                        </x-filament::badge>
                    @endif

                    <x-filament::link :href="$next['docs_url']" target="_blank" rel="noopener" color="gray" icon="heroicon-o-book-open">
                        {{ __('pondok.dashboard.setup.docs') }}
                    </x-filament::link>
                </div>
            </div>
        @endif

        {{-- Langkah lain yang belum selesai --}}
        @if ($later->isNotEmpty())
            <h3 class="mt-6 text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('pondok.dashboard.setup.remaining') }}
            </h3>

            <ol class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($later as $step)
                    <li class="flex gap-3 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold tabular-nums text-gray-700 dark:bg-white/10 dark:text-gray-300" aria-hidden="true">
                            {{ $step['number'] }}
                        </span>

                        <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ __("pondok.dashboard.setup.steps.{$step['key']}.title") }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __("pondok.dashboard.setup.steps.{$step['key']}.description") }}
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2">
                                @if ($step['url'])
                                    <x-filament::link :href="$step['url']" icon="heroicon-o-arrow-right" icon-position="after">
                                        {{ __("pondok.dashboard.setup.steps.{$step['key']}.action") }}
                                    </x-filament::link>
                                @else
                                    <x-filament::badge color="gray" icon="heroicon-o-server">
                                        {{ __("pondok.dashboard.setup.steps.{$step['key']}.server") }}
                                    </x-filament::badge>
                                @endif

                                <x-filament::link :href="$step['docs_url']" target="_blank" rel="noopener" color="gray" size="sm" icon="heroicon-o-book-open">
                                    {{ __('pondok.dashboard.setup.docs_short') }}
                                </x-filament::link>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Langkah yang sudah selesai, ringkas --}}
        @if ($completed->isNotEmpty())
            <h3 class="mt-6 text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('pondok.dashboard.setup.completed') }}
            </h3>

            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($completed as $step)
                    <li>
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">
                            {{ __("pondok.dashboard.setup.steps.{$step['key']}.title") }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-6">
            <x-filament::link :href="$featuresUrl" target="_blank" rel="noopener" size="sm" icon="heroicon-o-arrow-top-right-on-square" icon-position="after">
                {{ __('pondok.dashboard.setup.features_link') }}
            </x-filament::link>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

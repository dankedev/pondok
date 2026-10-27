@php
    $nextKey = collect($progress['steps'])->firstWhere('done', false)['key'] ?? null;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('pondok.dashboard.setup.heading')"
        :description="__('pondok.dashboard.setup.description')"
        icon="heroicon-o-rocket-launch"
    >
        <div class="flex items-baseline justify-between gap-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('pondok.dashboard.setup.progress', ['done' => $progress['done_count'], 'total' => $progress['total']]) }}
            </p>
            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ __('pondok.dashboard.setup.percent', ['percent' => $progress['percent']]) }}
            </p>
        </div>

        <div
            class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
            role="progressbar"
            aria-label="{{ __('pondok.dashboard.setup.progress_label') }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $progress['percent'] }}"
        >
            <div class="h-full rounded-full bg-primary-600 dark:bg-primary-500" style="width: {{ $progress['percent'] }}%"></div>
        </div>

        <ol class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($progress['steps'] as $step)
                <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 gap-3">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                        @else
                            <span class="mt-0.5 h-5 w-5 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
                        @endif

                        <div class="min-w-0">
                            <p class="text-sm font-medium {{ $step['done'] ? 'text-gray-600 dark:text-gray-400' : 'text-gray-950 dark:text-white' }}">
                                <span class="sr-only">{{ $step['done'] ? __('pondok.dashboard.setup.done') : __('pondok.dashboard.setup.pending') }}:</span>
                                {{ __("pondok.dashboard.setup.steps.{$step['key']}.title") }}
                            </p>
                            @unless ($step['done'])
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __("pondok.dashboard.setup.steps.{$step['key']}.description") }}
                                </p>
                            @endunless
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-4 ps-8 sm:ps-0">
                        <x-filament::link :href="$step['docs_url']" target="_blank" rel="noopener" color="gray" size="sm">
                            {{ __('pondok.dashboard.setup.docs') }}
                        </x-filament::link>

                        @if ($step['url'])
                            <x-filament::button
                                :href="$step['url']"
                                tag="a"
                                size="sm"
                                :color="$step['key'] === $nextKey ? 'primary' : 'gray'"
                                :outlined="$step['key'] !== $nextKey"
                            >
                                {{ __('pondok.dashboard.setup.open') }}
                            </x-filament::button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
            <x-filament::link :href="$featuresUrl" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" icon-position="after">
                {{ __('pondok.dashboard.setup.features_link') }}
            </x-filament::link>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

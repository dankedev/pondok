<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :icon="$icon">
        @if ($listUrl)
            <x-slot name="afterHeader">
                <x-filament::link :href="$listUrl" icon="heroicon-o-arrow-right" icon-position="after">
                    {{ __('pondok.dashboard.students.list_link') }}
                </x-filament::link>
            </x-slot>
        @endif

        @if ($summary['total'] === 0)
            <div class="flex flex-col items-center gap-2 py-6 text-center">
                <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('pondok.dashboard.students.empty') }}
                </p>
            </div>
        @else
            <p class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ \Illuminate\Support\Number::format($summary['total'], locale: 'id') }}
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-white/10 dark:text-gray-400">
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('pondok.dashboard.students.unit') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('pondok.dashboard.students.cohort') }}</th>
                            <th scope="col" class="py-2 text-end font-medium">{{ __('pondok.dashboard.students.count') }}</th>
                        </tr>
                    </thead>
                    @foreach ($summary['units'] as $unit)
                        <tbody class="border-b border-gray-200 dark:border-white/10">
                            @forelse ($unit['cohorts'] as $cohort)
                                <tr>
                                    @if ($loop->first)
                                        <th scope="rowgroup" rowspan="{{ count($unit['cohorts']) }}" class="py-2 pe-4 text-start align-top font-medium text-gray-950 dark:text-white">
                                            {{ $unit['name'] }}
                                        </th>
                                    @endif
                                    <td class="py-2 pe-4 text-gray-950 dark:text-white">{{ $cohort['name'] }}</td>
                                    <td class="py-2 text-end tabular-nums text-gray-950 dark:text-white">{{ $cohort['total'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <th scope="row" class="py-2 pe-4 text-start font-medium text-gray-950 dark:text-white">{{ $unit['name'] }}</th>
                                    <td colspan="2" class="py-2 text-gray-600 dark:text-gray-400">{{ __('pondok.dashboard.students.empty') }}</td>
                                </tr>
                            @endforelse

                            @if (count($unit['cohorts']) > 0)
                                <tr class="text-gray-600 dark:text-gray-400">
                                    <td></td>
                                    <td class="py-2 pe-4">{{ __('pondok.dashboard.students.subtotal') }}</td>
                                    <td class="py-2 text-end font-semibold tabular-nums">{{ $unit['total'] }}</td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

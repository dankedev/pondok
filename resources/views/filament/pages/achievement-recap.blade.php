@php
    $summary = $this->getSummary();
    $levels = \App\Models\Achievement::LEVELS;
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section :heading="__('pondok.achievement_recap.summary')">
        <dl style="display: flex; flex-wrap: wrap; gap: 2rem; margin-bottom: 1rem;">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('pondok.achievement_recap.total') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($summary['total'], 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('pondok.achievement_recap.participations') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($summary['participations'], 0, ',', '.') }}</dd>
            </div>
        </dl>

        <div style="overflow-x: auto;">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('pondok.achievement_recap.matrix_category') }}</th>
                        @foreach ($levels as $level)
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __("pondok.achievement.levels.{$level}") }}</th>
                        @endforeach
                        <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('pondok.achievement_recap.matrix_total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['matrix'] as $category => $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <th scope="row" class="px-3 py-2 text-start font-normal">{{ __("pondok.achievement.categories.{$category}") }}</th>
                            @foreach ($levels as $level)
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row[$level] }}</td>
                            @endforeach
                            <td class="px-3 py-2 text-end font-medium tabular-nums">{{ $summary['by_category'][$category] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" class="px-3 py-2 text-start font-medium">{{ __('pondok.achievement_recap.matrix_total') }}</th>
                        @foreach ($levels as $level)
                            <td class="px-3 py-2 text-end font-medium tabular-nums">{{ $summary['by_level'][$level] }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-end font-semibold tabular-nums">{{ $summary['total'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>

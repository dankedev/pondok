<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :icon="$icon">
        @if ($listUrl)
            <x-slot name="afterHeader">
                <x-filament::link :href="$listUrl" icon="heroicon-o-arrow-right" icon-position="after">
                    {{ __('pondok.dashboard.overdue_leaves.list_link') }}
                </x-filament::link>
            </x-slot>
        @endif

        @if ($count === 0)
            <div class="flex flex-col items-center gap-2 py-6 text-center">
                <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('pondok.dashboard.overdue_leaves.empty') }}
                </p>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <span class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $count }}</span>
                {{ __('pondok.dashboard.overdue_leaves.count_suffix') }}
            </p>

            <ul class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($requests as $request)
                    <li class="flex justify-between gap-4 py-3">
                        <span class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">{{ $request->student?->name }}</span>
                        <span class="shrink-0 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ __('pondok.dashboard.overdue_leaves.due', ['date' => $request->return_due->translatedFormat(\App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable::DATE_TIME_FORMAT)]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

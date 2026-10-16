<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :icon="$icon">
        @if ($listUrl)
            <x-slot name="afterHeader">
                <x-filament::link :href="$listUrl" icon="heroicon-o-arrow-right" icon-position="after">
                    {{ __('pondok.dashboard.achievements.list_link') }}
                </x-filament::link>
            </x-slot>
        @endif

        @if ($achievements->isEmpty())
            <div class="flex flex-col items-center gap-2 py-6 text-center">
                <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('pondok.dashboard.achievements.empty') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($achievements as $achievement)
                    <li class="flex gap-4 py-3">
                        <span class="w-24 shrink-0 text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ $achievement->achieved_on->locale('id')->translatedFormat('j M Y') }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $achievement->title }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __("pondok.achievement.levels.{$achievement->level}") }}
                                @if ($achievement->students->isNotEmpty())
                                    · {{ \App\Filament\Widgets\RecentAchievementsWidget::participantNames($achievement) }}
                                @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

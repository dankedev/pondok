<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardModule;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Kerangka widget dashboard modul prestasi (F018). Sumber metriknya diisi oleh fitur
 * modul prestasi; sampai itu widget selalu menampilkan "Modul belum tersedia."
 */
class RecentAchievementsWidget extends Widget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 60;

    protected string $view = 'filament.widgets.module-unavailable';

    public static function getDashboardPermission(): string
    {
        return 'view-student-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'prestasi';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.achievements.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedTrophy;
    }

    public static function getRequiredTables(): array
    {
        return ['achievements', 'achievement_student'];
    }

    public static function hasMetricSource(): bool
    {
        return false;
    }

    /**
     * @return array{heading: string, icon: Heroicon}
     */
    protected function getViewData(): array
    {
        return ['heading' => static::getDashboardHeading(), 'icon' => static::getDashboardIcon()];
    }
}

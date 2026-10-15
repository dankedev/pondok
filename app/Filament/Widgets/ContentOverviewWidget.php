<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardModule;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Kerangka widget dashboard modul konten (F018). Sumber metriknya diisi oleh fitur
 * modul konten; sampai itu widget selalu menampilkan "Modul belum tersedia."
 */
class ContentOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 30;

    protected ?string $pollingInterval = null;

    public static function getDashboardPermission(): string
    {
        return 'view-content-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'konten';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.content.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedNewspaper;
    }

    public static function getRequiredTables(): array
    {
        return ['posts', 'albums', 'videos'];
    }

    public static function hasMetricSource(): bool
    {
        return false;
    }

    protected function getHeading(): ?string
    {
        return static::getDashboardHeading();
    }

    protected function getStats(): array
    {
        return [];
    }
}

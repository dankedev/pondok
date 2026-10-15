<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardModule;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Kerangka widget dashboard modul psb (F018). Sumber metriknya diisi oleh fitur
 * modul psb; sampai itu widget selalu menampilkan "Modul belum tersedia."
 */
class PsbOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected ?string $pollingInterval = null;

    public static function getDashboardPermission(): string
    {
        return 'view-psb-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'psb';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.psb.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getRequiredTables(): array
    {
        return ['applicants'];
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

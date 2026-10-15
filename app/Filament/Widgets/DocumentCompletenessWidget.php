<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardModule;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Kerangka widget dashboard modul dokumen (F018). Sumber metriknya diisi oleh fitur
 * modul dokumen; sampai itu widget selalu menampilkan "Modul belum tersedia."
 */
class DocumentCompletenessWidget extends Widget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 50;

    protected string $view = 'filament.widgets.module-unavailable';

    public static function getDashboardPermission(): string
    {
        return 'view-student-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'dokumen';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.documents.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedDocumentText;
    }

    public static function getRequiredTables(): array
    {
        return ['documents', 'document_types'];
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

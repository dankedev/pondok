<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\SummarizeSystemStatus;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Status sistem (F018) untuk `view-settings-metrics`: tahun ajaran aktif,
 * saklar modul aktif, dan pengguna aktif dari `SummarizeSystemStatus`.
 */
class SystemOverviewWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 70;

    protected ?string $pollingInterval = null;

    public static function getDashboardPermission(): string
    {
        return 'view-settings-metrics';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.system.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public static function getRequiredTables(): array
    {
        return ['academic_years', 'settings', 'users'];
    }

    protected function getHeading(): ?string
    {
        return static::getDashboardHeading();
    }

    public function getSectionContentComponent(): Component
    {
        /** @var Section $section */
        $section = parent::getSectionContentComponent();

        $url = static::urlIfRouteAvailable(
            AcademicYearResource::getRouteBaseName().'.index',
            AcademicYearResource::canViewAny(),
        );

        return $url === null ? $section : $section->afterHeader([
            Action::make('academicYears')
                ->label(__('pondok.dashboard.system.academic_year_link'))
                ->icon(Heroicon::OutlinedArrowRight)
                ->iconPosition('after')
                ->link()
                ->url($url),
        ]);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        // Skema widget bisa disusun sebelum render; metrik tidak dihitung bila modul tidak tersedia.
        if (! static::isModuleAvailable()) {
            return [];
        }

        $status = app(SummarizeSystemStatus::class)->handle();
        $academicYear = $status['active_academic_year'];

        return [
            Stat::make(__('pondok.dashboard.system.academic_year'), $academicYear ?? '-')
                ->description($academicYear === null ? __('pondok.dashboard.system.no_academic_year') : null)
                ->color($academicYear === null ? 'warning' : null),
            Stat::make(__('pondok.dashboard.system.modules'), __('pondok.dashboard.system.modules_value', [
                'enabled' => $status['enabled_module_count'],
                'total' => $status['module_count'],
            ])),
            Stat::make(__('pondok.dashboard.system.users'), Number::format($status['active_user_count'], locale: 'id')),
        ];
    }
}

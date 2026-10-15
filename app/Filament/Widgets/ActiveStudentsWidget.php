<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\SummarizeActiveStudents;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\Students\StudentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Santri aktif per jenjang dan angkatan (F018) untuk `view-student-metrics`,
 * dihitung oleh `SummarizeActiveStudents` dalam cakupan jenjang user.
 */
class ActiveStudentsWidget extends Widget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.active-students';

    public static function getDashboardPermission(): string
    {
        return 'view-student-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'akademik';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.students.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedAcademicCap;
    }

    public static function getRequiredTables(): array
    {
        return ['students', 'units', 'cohorts'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'heading' => static::getDashboardHeading(),
            'icon' => static::getDashboardIcon(),
            'summary' => app(SummarizeActiveStudents::class)->handle(),
            'listUrl' => static::urlIfRouteAvailable(
                StudentResource::getRouteBaseName().'.index',
                StudentResource::canViewAny(),
            ),
        ];
    }
}

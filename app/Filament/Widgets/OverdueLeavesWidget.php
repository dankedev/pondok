<?php

namespace App\Filament\Widgets;

use App\Enums\LeaveRequestStatus;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Santri yang masih di luar padahal batas kembali sudah lewat (F024 D8): jumlah, lima nama yang paling lama
 * lewat batas, dan tautan ke daftar izin terfilter. Hanya izin yang terlihat user (`LeaveRequest::scopeVisibleTo`).
 */
class OverdueLeavesWidget extends Widget
{
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 70;

    public const LIMIT = 5;

    protected string $view = 'filament.widgets.overdue-leaves';

    public static function getDashboardPermission(): string
    {
        return 'view-leave-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'perizinan';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.overdue_leaves.heading');
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedArrowRightStartOnRectangle;
    }

    public static function getRequiredTables(): array
    {
        return ['leave_requests'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $overdue = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Out->value)
            ->late();

        return [
            'heading' => static::getDashboardHeading(),
            'icon' => static::getDashboardIcon(),
            'count' => (clone $overdue)->count(),
            'requests' => $overdue->with('student')->orderBy('return_due')->orderBy('id')->limit(self::LIMIT)->get(),
            'listUrl' => static::urlIfRouteAvailable(
                LeaveRequestResource::getRouteBaseName().'.index',
                LeaveRequestResource::canViewAny(),
                ['filters' => ['late' => ['isActive' => true]]],
            ),
        ];
    }
}

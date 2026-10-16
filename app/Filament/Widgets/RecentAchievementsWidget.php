<?php

namespace App\Filament\Widgets;

use App\Actions\Prestasi\ListRecentAchievements;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\Achievements\AchievementResource;
use App\Models\Achievement;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

/**
 * Prestasi terverifikasi terbaru (F015) dalam cakupan jenjang user. Butuh
 * `view-student-metrics` dan akses daftar prestasi (`view-any:achievement`).
 */
class RecentAchievementsWidget extends Widget
{
    use InteractsWithDashboardModule {
        canView as canViewMetrics;
    }

    protected static bool $isLazy = false;

    protected static ?int $sort = 60;

    public const LIMIT = 5;

    /** Nama peserta per baris sebelum "dan :count lainnya". */
    public const VISIBLE_PARTICIPANTS = 2;

    protected string $view = 'filament.widgets.recent-achievements';

    public static function getDashboardPermission(): string
    {
        return 'view-student-metrics';
    }

    public static function canView(): bool
    {
        return static::canViewMetrics() && Gate::allows('viewAny', Achievement::class);
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

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'heading' => static::getDashboardHeading(),
            'icon' => static::getDashboardIcon(),
            'achievements' => app(ListRecentAchievements::class)->handle(self::LIMIT),
            'listUrl' => static::urlIfRouteAvailable(
                AchievementResource::getRouteBaseName().'.index',
                AchievementResource::canViewAny(),
            ),
        ];
    }

    /**
     * Nama peserta terlihat, maksimal dua lalu "dan :count lainnya".
     */
    public static function participantNames(Achievement $achievement): string
    {
        $names = $achievement->students->pluck('name');
        $text = $names->take(self::VISIBLE_PARTICIPANTS)->implode(', ');

        return $names->count() > self::VISIBLE_PARTICIPANTS
            ? $text.' '.__('pondok.dashboard.achievements.others', ['count' => $names->count() - self::VISIBLE_PARTICIPANTS])
            : $text;
    }
}

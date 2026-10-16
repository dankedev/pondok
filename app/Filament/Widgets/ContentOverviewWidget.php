<?php

namespace App\Filament\Widgets;

use App\Actions\Konten\SummarizeContent;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\Posts\PostResource;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan konten situs (F016/F018) untuk `view-content-metrics`: jumlah post
 * terbit, terjadwal, dan draft, serta album, foto, dan video, dihitung oleh
 * `SummarizeContent`.
 */
class ContentOverviewWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 30;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['@xl' => 3, '!@lg' => 3];

    private const STAT_COLORS = [
        'posts_published' => 'success',
        'posts_scheduled' => 'info',
        'posts_draft' => 'gray',
    ];

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

    /**
     * Tabel yang dibaca `SummarizeContent`.
     */
    public static function getRequiredTables(): array
    {
        return ['posts', 'albums', 'photos', 'videos'];
    }

    protected function getHeading(): ?string
    {
        return static::getDashboardHeading();
    }

    public function getSectionContentComponent(): Component
    {
        /** @var Section $section */
        $section = parent::getSectionContentComponent();

        $url = static::urlIfRouteAvailable('filament.admin.resources.posts.index', PostResource::canViewAny());

        return $url === null ? $section : $section->afterHeader([
            Action::make('postList')
                ->label(__('pondok.dashboard.content.list_link'))
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
        if (! static::isModuleAvailable()) {
            return [];
        }

        $stats = [];

        foreach (app(SummarizeContent::class)->handle() as $key => $count) {
            $stats[] = Stat::make(__("pondok.dashboard.content.{$key}"), $count)
                ->color(self::STAT_COLORS[$key] ?? null);
        }

        return $stats;
    }
}

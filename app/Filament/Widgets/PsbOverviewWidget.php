<?php

namespace App\Filament\Widgets;

use App\Actions\Psb\SummarizeApplicants;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Models\Applicant;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pendaftaran santri baru (F011) untuk `view-psb-metrics`: total pendaftar
 * tahun ajaran gelombang terbaru beserta jumlah per status, dihitung oleh
 * `SummarizeApplicants` dalam cakupan jenjang user.
 */
class PsbOverviewWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['@xl' => 3, '!@lg' => 3];

    /** @var array{academic_year: mixed, total: int, by_status: array<string, int>, last_7_days: int}|null */
    private ?array $summary = null;

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

    /**
     * Tabel yang dibaca `SummarizeApplicants`.
     */
    public static function getRequiredTables(): array
    {
        return ['applicants', 'admission_waves', 'academic_years'];
    }

    protected function getHeading(): ?string
    {
        if (! static::isModuleAvailable()) {
            return static::getDashboardHeading();
        }

        $year = $this->summary()['academic_year'];

        return $year === null
            ? static::getDashboardHeading()
            : __('pondok.dashboard.psb.heading_year', ['year' => $year->name]);
    }

    public function getSectionContentComponent(): Component
    {
        /** @var Section $section */
        $section = parent::getSectionContentComponent();

        $url = static::urlIfRouteAvailable('filament.admin.resources.applicants.index', ApplicantResource::canViewAny());

        return $url === null ? $section : $section->afterHeader([
            Action::make('applicantList')
                ->label(__('pondok.dashboard.psb.list_link'))
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

        $summary = $this->summary();
        $stats = [
            Stat::make(__('pondok.dashboard.psb.total'), $summary['total'])
                ->description($summary['academic_year'] === null
                    ? __('pondok.dashboard.psb.no_waves')
                    : __('pondok.dashboard.psb.total_description', ['count' => $summary['last_7_days']])),
        ];

        foreach ($summary['by_status'] as $status => $count) {
            $stats[] = Stat::make(__("pondok.applicant.statuses.{$status}"), $count)
                ->color((new Applicant(['status' => $status]))->statusColor());
        }

        return $stats;
    }

    /**
     * @return array{academic_year: mixed, total: int, by_status: array<string, int>, last_7_days: int}
     */
    private function summary(): array
    {
        return $this->summary ??= app(SummarizeApplicants::class)->handle();
    }
}

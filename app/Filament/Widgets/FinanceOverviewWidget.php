<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\SummarizeCurrentMonthFinance;
use App\Filament\Concerns\InteractsWithDashboardModule;
use App\Filament\Pages\FinanceReport;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Keuangan bulan berjalan (F018) untuk `view-finance-metrics`: ditagihkan,
 * terbayar, lewat jatuh tempo, dan kas diterima, dihitung oleh
 * `SummarizeCurrentMonthFinance` dalam cakupan jenjang user.
 */
class FinanceOverviewWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use InteractsWithDashboardModule;

    protected static bool $isLazy = false;

    protected static ?int $sort = 10;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['@md' => 2, '@4xl' => 4, '!@md' => 2, '!@xl' => 4];

    public static function getDashboardPermission(): string
    {
        return 'view-finance-metrics';
    }

    public static function getDashboardModule(): ?string
    {
        return 'keuangan';
    }

    public static function getDashboardHeading(): string
    {
        return __('pondok.dashboard.finance.heading', [
            'period' => CarbonImmutable::now(config('app.timezone'))->translatedFormat('F Y'),
        ]);
    }

    public static function getDashboardIcon(): Heroicon
    {
        return Heroicon::OutlinedBanknotes;
    }

    /**
     * Tabel yang dibaca `SummarizeInvoices` dan `SummarizeCashReceipts` (termasuk
     * rincian per jenis biaya yang tidak ditampilkan di widget). Daftar sengaja
     * mengikuti kebutuhan Action yang dipanggil agar widget tidak pernah error.
     */
    public static function getRequiredTables(): array
    {
        return ['invoices', 'invoice_items', 'payments', 'payment_allocations', 'fee_types'];
    }

    protected function getHeading(): ?string
    {
        return static::getDashboardHeading();
    }

    public function getSectionContentComponent(): Component
    {
        /** @var Section $section */
        $section = parent::getSectionContentComponent();

        $url = static::urlIfRouteAvailable(FinanceReport::getRouteName(), FinanceReport::canAccess());

        return $url === null ? $section : $section->afterHeader([
            Action::make('financeReport')
                ->label(__('pondok.dashboard.finance.report_link'))
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

        $summary = app(SummarizeCurrentMonthFinance::class)->handle();
        $isEmpty = $summary['invoice_count'] === 0 && $summary['payment_count'] === 0;

        return [
            Stat::make(__('pondok.dashboard.finance.billed'), Payment::formatRupiah($summary['billed']))
                ->description($isEmpty
                    ? __('pondok.dashboard.finance.no_invoices')
                    : __('pondok.dashboard.finance.billed_description', ['count' => $summary['invoice_count']])),
            Stat::make(__('pondok.dashboard.finance.paid'), Payment::formatRupiah($summary['paid']))
                ->description($summary['collection_rate'] === null
                    ? '-'
                    : __('pondok.dashboard.finance.paid_description', ['rate' => $summary['collection_rate']]))
                ->color('success'),
            Stat::make(__('pondok.dashboard.finance.overdue'), Payment::formatRupiah($summary['overdue']))
                ->description(__('pondok.dashboard.finance.overdue_description', ['count' => $summary['overdue_student_count']]))
                ->color($summary['overdue'] > 0 ? 'danger' : 'gray'),
            Stat::make(__('pondok.dashboard.finance.cash'), Payment::formatRupiah($summary['cash_received']))
                ->description(__('pondok.dashboard.finance.cash_description', ['count' => $summary['payment_count']])),
        ];
    }
}

<?php

namespace App\Jobs\Keuangan;

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Actions\Keuangan\FinanceReportFilter;
use App\Enums\Module;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Menyiapkan CSV laporan keuangan (F068) di latar belakang lewat
 * `ExportFinanceReportCsv`, lalu mengirim tautan unduh ke pemicu sebagai
 * notifikasi database Filament.
 *
 * Selama ekspor, guard dipasang ke pemicu agar `UnitScope` dan validasi
 * cakupan `FinanceReportFilter::make()` berlaku seperti di panel; guard
 * dikembalikan di `finally` agar worker (atau request pada antrean `sync`)
 * tidak tertinggal login sebagai pemicu. Akses yang dicabut sebelum Job
 * berjalan membuat Job selesai diam-diam. Filter yang kini tidak valid menjadi
 * notifikasi `danger`; exception lain ditangani `failed()` dengan pesan generik.
 */
class ExportFinanceReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array{period_from: string, period_to: string, unit_id: int|null, billing_group: string|null}  $filter  hasil `FinanceReportFilter::toArray()`
     */
    public function __construct(
        public ?User $user,
        public string $section,
        public array $filter,
    ) {}

    public function handle(ExportFinanceReportCsv $export): void
    {
        if ($this->user === null || ! Module::Keuangan->isEnabled() || ! $this->user->can('view:finance-report')) {
            return;
        }

        $previous = Auth::user();
        Auth::setUser($this->user);

        try {
            $filter = FinanceReportFilter::make($this->filter['period_from'], $this->filter['period_to'], $this->filter['unit_id'], $this->filter['billing_group']);
            $path = $export->handle($filter, $this->section, $this->user);
        } catch (ValidationException $exception) {
            $this->send(Notification::make()->title(collect($exception->errors())->flatten()->first())->danger());

            return;
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetUser();
        }

        $this->send(Notification::make()
            ->title(__('pondok.finance_report.export.ready', [
                'section' => __("pondok.finance_report.export.sections.{$this->section}"),
                'period' => $this->periodLabel($filter),
            ]))
            ->body(__('pondok.finance_report.export.ready_body'))
            ->success()
            ->actions([
                Action::make('download')
                    ->label(__('pondok.finance_report.export.download'))
                    ->url(route('filament.admin.keuangan.finance-export', basename(dirname($path)), absolute: false)),
            ]));
    }

    public function failed(?Throwable $exception): void
    {
        $this->send(Notification::make()->title(__('pondok.finance_report.export.failed'))->danger());
    }

    /**
     * Pemicu bisa `null` saat `failed()` dipanggil setelah user dihapus.
     */
    private function send(Notification $notification): void
    {
        if ($this->user !== null) {
            $notification->sendToDatabase($this->user);
        }
    }

    private function periodLabel(FinanceReportFilter $filter): string
    {
        $from = $filter->periodFrom->translatedFormat('F Y');
        $to = $filter->periodTo->translatedFormat('F Y');

        return $from === $to ? $from : __('pondok.finance_report.export.period_range', ['from' => $from, 'to' => $to]);
    }
}

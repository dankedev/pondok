<?php

namespace App\Console\Commands;

use App\Actions\Keuangan\GenerateAnnualInvoices;
use App\Models\AcademicYear;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Menjalankan generator tagihan tahunan. Tanpa opsi (scheduler, tanggal 1),
 * hanya berjalan pada bulan `keuangan.annual_billing_month` untuk tahun
 * ajaran yang memuat hari ini. Dengan `--academic-year`, berjalan kapan saja
 * untuk tahun ajaran itu. Tanpa user, sehingga `generated_by` kosong.
 */
#[Signature('keuangan:generate-annual-invoices {--academic-year= : Nama tahun ajaran, misalnya 2026/2027}')]
#[Description('Membuat tagihan biaya tahunan untuk semua santri aktif')]
class GenerateAnnualInvoicesCommand extends Command
{
    public function handle(GenerateAnnualInvoices $generate): int
    {
        if (! Setting::moduleEnabled('keuangan')) {
            $this->components->info(__('pondok.invoice.command.module_disabled'));

            return self::SUCCESS;
        }

        $name = $this->option('academic-year');

        if ($name === null || $name === '') {
            $month = GenerateAnnualInvoices::billingMonth();
            $today = CarbonImmutable::now('Asia/Jakarta');

            if ($month === null) {
                $this->components->info(__('pondok.invoice.command.annual_not_scheduled'));

                return self::SUCCESS;
            }

            if ($today->month !== $month) {
                $this->components->info(__('pondok.invoice.command.annual_not_this_month'));

                return self::SUCCESS;
            }

            $year = AcademicYear::query()
                ->whereDate('starts_on', '<=', $today->toDateString())
                ->whereDate('ends_on', '>=', $today->toDateString())
                ->orderByDesc('starts_on')
                ->first();
        } else {
            $year = AcademicYear::query()->where('name', $name)->first();
        }

        if ($year === null) {
            $this->components->error(__('pondok.invoice.command.annual_no_academic_year'));

            return self::FAILURE;
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        try {
            $result = $generate->handle($year);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info(__('pondok.invoice.command.summary', [
            'period' => $year->name,
            'created' => $result['created_invoices'],
            'added' => $result['added_items'],
            'existing' => $result['existing_invoices'],
            'skipped' => count($result['skipped']),
        ]));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Menjalankan generator tagihan bulanan dari scheduler (tanggal 1) atau
 * manual. Tanpa user, sehingga `generated_by` kosong. Modul mati: keluar
 * tanpa membuat data.
 */
#[Signature('keuangan:generate-invoices {--period= : Bulan tagihan YYYY-MM, bawaan bulan berjalan}')]
#[Description('Membuat tagihan bulanan untuk semua santri aktif')]
class GenerateMonthlyInvoicesCommand extends Command
{
    public function handle(GenerateMonthlyInvoices $generate): int
    {
        if (! Setting::moduleEnabled('keuangan')) {
            $this->components->info(__('pondok.invoice.command.module_disabled'));

            return self::SUCCESS;
        }

        $period = $this->period();

        if ($period === null) {
            $this->components->error(__('pondok.invoice.command.invalid_period'));

            return self::FAILURE;
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        try {
            $result = $generate->handle($period);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info(__('pondok.invoice.command.summary', [
            'period' => $period->translatedFormat('F Y'),
            'created' => $result['created_invoices'],
            'added' => $result['added_items'],
            'existing' => $result['existing_invoices'],
            'skipped' => count($result['skipped']),
        ]));

        return self::SUCCESS;
    }

    private function period(): ?CarbonImmutable
    {
        $option = $this->option('period');

        if ($option === null || $option === '') {
            return CarbonImmutable::now('Asia/Jakarta')->startOfMonth();
        }

        if (! is_string($option) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $option, $matches) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0, 'Asia/Jakarta');
    }
}

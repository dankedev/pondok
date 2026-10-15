<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Nomor tagihan `INV-{Ym}-NNNNN` (5 digit, urut per bulan periode).
 *
 * Penghitung dibaca tanpa cakupan jenjang dan tanpa filter status: tagihan
 * jenjang lain dan tagihan `batal` tetap memakai nomornya. Lebar tetap
 * sehingga urut string sama dengan urut angka.
 */
class GenerateInvoiceNumber
{
    public const MAX_SEQUENCE = 99999;

    /**
     * Nomor urut terbesar yang sudah dipakai di bulan `$month`, 0 bila belum ada.
     */
    public function lastSequence(CarbonInterface $month): int
    {
        $last = Invoice::withoutUnitScope()
            ->where('invoice_no', 'like', $this->prefix($month).'%')
            ->max('invoice_no');

        return $last === null ? 0 : (int) substr((string) $last, -5);
    }

    public function format(CarbonInterface $month, int $sequence): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new LogicException("Nomor urut tagihan {$sequence} di luar rentang 1-".self::MAX_SEQUENCE.'.');
        }

        return $this->prefix($month).str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor berikutnya untuk satu tagihan (dipakai PSB, F010/F011).
     */
    public function handle(CarbonInterface $month): string
    {
        return $this->format($month, $this->lastSequence($month) + 1);
    }

    private function prefix(CarbonInterface $month): string
    {
        return 'INV-'.$month->format('Ym').'-';
    }
}

<?php

namespace App\Actions\Keuangan;

use App\Models\Payment;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Nomor kuitansi `KW-{Ym}-NNNNN` (5 digit, urut per bulan pencatatan).
 *
 * Penghitung dibaca tanpa cakupan jenjang dan tanpa filter status: pembayaran
 * jenjang lain dan pembayaran `batal` tetap memakai nomornya. Lebar tetap
 * sehingga urut string sama dengan urut angka.
 */
class GenerateReceiptNumber
{
    public const MAX_SEQUENCE = 99999;

    /**
     * Nomor urut terbesar yang sudah dipakai di bulan `$month`, 0 bila belum ada.
     */
    public function lastSequence(CarbonInterface $month): int
    {
        $last = Payment::withoutUnitScope()
            ->where('receipt_no', 'like', $this->prefix($month).'%')
            ->max('receipt_no');

        return $last === null ? 0 : (int) substr((string) $last, -5);
    }

    public function format(CarbonInterface $month, int $sequence): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new LogicException("Nomor urut kuitansi {$sequence} di luar rentang 1-".self::MAX_SEQUENCE.'.');
        }

        return $this->prefix($month).str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function handle(CarbonInterface $month): string
    {
        return $this->format($month, $this->lastSequence($month) + 1);
    }

    private function prefix(CarbonInterface $month): string
    {
        return 'KW-'.$month->format('Ym').'-';
    }
}

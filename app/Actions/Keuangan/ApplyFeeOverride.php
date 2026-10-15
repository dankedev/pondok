<?php

namespace App\Actions\Keuangan;

use App\Models\FeeOverride;

/**
 * Menghitung nominal satu jenis biaya dari tarif dasar dan keringanan.
 * Murni hitung, tanpa query; hasilnya siap disalin ke item tagihan (F006).
 *
 * - Tanpa tarif dasar: hanya mode nominal yang menghasilkan tagihan (base =
 *   amount = nilai keringanan); selain itu null, pemanggil mencatat "tarif
 *   belum diatur" dan melewati jenis biaya ini.
 * - Nominal: bayar `min(nilai, tarif)`. Ini penyimpangan sadar dari
 *   pseudocode ERD awal (`$ovr->value`): keringanan tidak pernah menaikkan
 *   tagihan dan `discount_amount` tidak pernah negatif.
 * - Potongan: `max(0, tarif - nilai)`.
 * - Persen: diskon `intdiv(tarif * persen, 100)` (dibulatkan ke bawah).
 *
 * Selalu berlaku `base_amount - discount_amount === amount`, ketiganya int >= 0.
 */
class ApplyFeeOverride
{
    /**
     * @return array{base_amount: int, discount_amount: int, amount: int, fee_override_id: int|null}|null
     */
    public function handle(?int $baseAmount, ?FeeOverride $override): ?array
    {
        if ($baseAmount === null) {
            if ($override?->mode !== FeeOverride::MODE_NOMINAL) {
                return null;
            }

            return $this->result($override->value, $override->value, $override);
        }

        if ($override === null) {
            return $this->result($baseAmount, $baseAmount, null);
        }

        $amount = match ($override->mode) {
            FeeOverride::MODE_NOMINAL => min($override->value, $baseAmount),
            FeeOverride::MODE_DISCOUNT => max(0, $baseAmount - $override->value),
            FeeOverride::MODE_PERCENT => $baseAmount - min($baseAmount, intdiv($baseAmount * $override->value, 100)),
        };

        return $this->result($baseAmount, $amount, $override);
    }

    /**
     * @return array{base_amount: int, discount_amount: int, amount: int, fee_override_id: int|null}
     */
    private function result(int $base, int $amount, ?FeeOverride $override): array
    {
        return [
            'base_amount' => $base,
            'discount_amount' => $base - $amount,
            'amount' => $amount,
            'fee_override_id' => $override?->id,
        ];
    }
}

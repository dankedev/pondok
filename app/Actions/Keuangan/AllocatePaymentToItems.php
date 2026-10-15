<?php

namespace App\Actions\Keuangan;

use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Membagi nominal pembayaran ke item tagihan (perhitungan murni, tanpa query).
 *
 * Urutan: `fee_types.priority` naik (dibaca saat pembayaran, bukan snapshot),
 * lalu id item naik. Item Rp 0 dan item lunas dilewati, sehingga tidak ada
 * alokasi bernilai 0.
 */
class AllocatePaymentToItems
{
    /**
     * @param  Collection<int, InvoiceItem>  $items  item dengan relasi `feeType` termuat
     * @return list<array{invoice_item_id: int, amount: int}>
     */
    public function handle(Collection $items, int $amount): array
    {
        $ordered = $items
            ->sortBy([
                fn (InvoiceItem $a, InvoiceItem $b): int => $a->feeType->priority <=> $b->feeType->priority,
                fn (InvoiceItem $a, InvoiceItem $b): int => $a->getKey() <=> $b->getKey(),
            ])
            ->values();

        $left = $amount;
        $allocations = [];

        foreach ($ordered as $item) {
            if ($left === 0) {
                break;
            }

            $share = min($item->remainingAmount(), $left);

            if ($share <= 0) {
                continue;
            }

            $allocations[] = ['invoice_item_id' => (int) $item->getKey(), 'amount' => $share];
            $left -= $share;
        }

        if ($left > 0) {
            throw new LogicException("Nominal {$amount} melebihi sisa item tagihan.");
        }

        return $allocations;
    }
}

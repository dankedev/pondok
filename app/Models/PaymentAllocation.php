<?php

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bagian satu pembayaran untuk satu item tagihan. Berlaku selama
 * pembayarannya `diterima`; tidak pernah diubah atau dihapus, termasuk saat
 * pembayaran dibatalkan, agar kuitansi lama tetap bisa dicetak persis.
 *
 * Tanpa activitylog sendiri (tercakup di log pembayaran).
 */
#[Fillable(['payment_id', 'invoice_item_id', 'amount'])]
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu jenis biaya dalam tagihan. Nominal adalah snapshot dan tidak diubah
 * setelah dibuat; selalu `base_amount - discount_amount = amount`.
 *
 * `rate_source`: `angkatan` bila tarif dasar adalah tarif angkatan, `jenjang`
 * untuk tarif lain, `manual` bila tidak ada tarif dan nominal berasal dari
 * keringanan mode nominal.
 *
 * Tanpa activitylog per item; audit generator berupa satu ringkasan per eksekusi.
 */
#[Fillable(['invoice_id', 'fee_type_id', 'description', 'base_amount', 'discount_amount', 'amount', 'rate_source', 'fee_override_id', 'paid_amount'])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    public const RATE_SOURCE_COHORT = 'angkatan';

    public const RATE_SOURCE_UNIT = 'jenjang';

    public const RATE_SOURCE_MANUAL = 'manual';

    public const RATE_SOURCES = [
        self::RATE_SOURCE_COHORT,
        self::RATE_SOURCE_UNIT,
        self::RATE_SOURCE_MANUAL,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'paid_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'integer',
            'discount_amount' => 'integer',
            'amount' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function feeOverride(): BelongsTo
    {
        return $this->belongsTo(FeeOverride::class);
    }

    /**
     * Alokasi pembayaran ke item ini, termasuk milik pembayaran `batal`.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function remainingAmount(): int
    {
        return max(0, $this->amount - $this->paid_amount);
    }
}

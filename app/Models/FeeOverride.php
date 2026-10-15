<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\FeeOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Keringanan satu jenis biaya untuk santri (atau pendaftar) dengan masa
 * berlaku inklusif. `valid_until` NULL berarti sampai lulus. Tidak dihapus:
 * diakhiri lewat `EndFeeOverride`, dikoreksi lewat `SaveFeeOverride`.
 *
 * Tanpa cakupan jenjang: resolver dipanggil scheduler tanpa user login dan
 * keringanan pendaftar belum punya santri. Panel membatasi lewat relasi
 * `student` (global scope `Student`).
 */
#[Fillable(['student_id', 'applicant_id', 'fee_type_id', 'scholarship_id', 'mode', 'value', 'reason', 'valid_from', 'valid_until', 'approved_by'])]
class FeeOverride extends Model
{
    /** @use HasFactory<FeeOverrideFactory> */
    use HasFactory, LogsActivity;

    /** Bayar sejumlah nilai (0 = gratis). */
    public const MODE_NOMINAL = 'nominal';

    /** Tarif dikurangi rupiah. */
    public const MODE_DISCOUNT = 'potongan';

    /** Tarif dikurangi persen 0-100. */
    public const MODE_PERCENT = 'persen';

    public const MODES = [
        self::MODE_NOMINAL,
        self::MODE_DISCOUNT,
        self::MODE_PERCENT,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('keuangan')
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class)->withTrashed();
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Keringanan yang masa berlakunya beririsan dengan rentang inklusif `$start`..`$end`.
     * Perbandingan `whereDate` dengan string `Y-m-d` agar portabel di SQLite/MySQL/Postgres.
     */
    public function scopeActiveBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query
            ->whereDate('valid_from', '<=', $end->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', $start->toDateString()));
    }

    public function scopeActiveOn(Builder $query, CarbonInterface $date): void
    {
        $this->scopeActiveBetween($query, $date, $date);
    }

    public function isFree(): bool
    {
        return $this->mode === self::MODE_NOMINAL && $this->value === 0;
    }

    /**
     * Teks keringanan, mis. "Gratis", "Bayar Rp 250.000", "Potongan 50%".
     */
    public function valueLabel(): string
    {
        return self::formatValue($this->mode, $this->value);
    }

    public static function formatValue(string $mode, int $value): string
    {
        $amount = 'Rp '.number_format($value, 0, ',', '.');

        return match ($mode) {
            self::MODE_NOMINAL => $value === 0
                ? __('pondok.fee_override.value_labels.free')
                : __('pondok.fee_override.value_labels.nominal', ['amount' => $amount]),
            self::MODE_DISCOUNT => __('pondok.fee_override.value_labels.potongan', ['amount' => $amount]),
            self::MODE_PERCENT => __('pondok.fee_override.value_labels.persen', ['percent' => $value]),
            default => (string) $value,
        };
    }
}

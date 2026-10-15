<?php

namespace App\Models;

use Database\Factories\FeeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tarif satu jenis biaya, berlaku mulai tahun ajaran tertentu. Angkatan,
 * asrama, dan gelombang PSB opsional; NULL berarti berlaku umum.
 *
 * Kolom generated `cohort_key`, `dormitory_key`, `wave_key` diisi database
 * dan hanya dipakai unique `fee_rates_combination_unique`. Tidak memakai
 * cakupan jenjang: resolver dipanggil scheduler tanpa user login.
 */
#[Fillable(['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_id', 'dormitory_id', 'admission_wave_id', 'amount'])]
class FeeRate extends Model
{
    /** @use HasFactory<FeeRateFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
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

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function effectiveFromYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'effective_from_year_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(Dormitory::class);
    }

    /**
     * Tarif khusus angkatan (dipakai F006 untuk `rate_source`).
     */
    public function isCohortRate(): bool
    {
        return $this->cohort_id !== null;
    }
}

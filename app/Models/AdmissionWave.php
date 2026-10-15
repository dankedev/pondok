<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AdmissionWaveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Gelombang PSB untuk satu tahun ajaran tujuan. Lintas jenjang. Tidak dihapus,
 * cukup ditutup lewat `is_open`. Rentang tanggal dalam satu tahun ajaran tidak
 * beririsan (dijaga `SaveAdmissionWave`). Kuota belum ditegakkan di sini (F010).
 */
#[Fillable(['academic_year_id', 'name', 'opens_on', 'closes_on', 'quota', 'is_open'])]
class AdmissionWave extends Model
{
    /** @use HasFactory<AdmissionWaveFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'closes_on' => 'date',
            'quota' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('psb')
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    /**
     * Gelombang yang terbuka dan tanggalnya (inklusif) mencakup `$date`.
     */
    public function scopeAcceptingRegistrationsOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->format('Y-m-d');

        $query->where('is_open', true)
            ->whereDate('opens_on', '<=', $day)
            ->whereDate('closes_on', '>=', $day);
    }

    public function isAcceptingRegistrationsOn(CarbonInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->is_open
            && $this->opens_on->format('Y-m-d') <= $day
            && $this->closes_on->format('Y-m-d') >= $day;
    }
}

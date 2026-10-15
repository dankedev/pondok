<?php

namespace App\Models;

use Database\Factories\AdmissionTrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jalur pendaftaran (Reguler, Prestasi, Tahfidz, Yatim). Program beasiswa
 * jalur diterapkan otomatis saat pendaftar diterima (F011) ke jenis biaya
 * yang dipetakan di `admission_track_fee_type`. Tidak dihapus, cukup
 * dinonaktifkan.
 */
#[Fillable(['code', 'name', 'scholarship_id', 'terms', 'is_active'])]
class AdmissionTrack extends Model
{
    /** @use HasFactory<AdmissionTrackFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    /** Frekuensi jenis biaya yang boleh dipetakan ke beasiswa jalur. */
    public const SCHOLARSHIP_FREQUENCIES = [
        FeeType::FREQUENCY_ON_ACCEPTANCE,
        FeeType::FREQUENCY_MONTHLY,
        FeeType::FREQUENCY_YEARLY,
    ];

    public function feeTypes(): BelongsToMany
    {
        return $this->belongsToMany(FeeType::class)->withTimestamps();
    }

    /**
     * Jenis biaya penerima beasiswa jalur: pemetaan yang aktif dan berfrekuensi
     * sah; pemetaan kosong berarti semua jenis biaya `saat_diterima` yang aktif.
     *
     * @return Collection<int, FeeType>
     */
    public function scholarshipFeeTypes(): Collection
    {
        $hasMapping = $this->feeTypes()->exists();

        return FeeType::query()
            ->active()
            ->when(
                $hasMapping,
                fn (Builder $query) => $query
                    ->whereIn('frequency', self::SCHOLARSHIP_FREQUENCIES)
                    ->whereHas('admissionTracks', fn (Builder $query) => $query->whereKey($this->getKey())),
                fn (Builder $query) => $query->where('frequency', FeeType::FREQUENCY_ON_ACCEPTANCE),
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

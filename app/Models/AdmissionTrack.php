<?php

namespace App\Models;

use Database\Factories\AdmissionTrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jalur pendaftaran (Reguler, Prestasi, Tahfidz, Yatim). Program beasiswa
 * jalur diterapkan otomatis saat pendaftar diterima (F011). Tidak dihapus,
 * cukup dinonaktifkan.
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

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

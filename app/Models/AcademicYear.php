<?php

namespace App\Models;

use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_on', 'ends_on', 'is_active'])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Gelombang PSB yang menerima pendaftar untuk tahun ajaran ini.
     */
    public function admissionWaves(): HasMany
    {
        return $this->hasMany(AdmissionWave::class);
    }

    /**
     * Tarif yang berlaku mulai tahun ajaran ini.
     */
    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class, 'effective_from_year_id');
    }

    /**
     * Prestasi yang tahun ajarannya diturunkan dari tanggal prestasi (F015).
     */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }
}

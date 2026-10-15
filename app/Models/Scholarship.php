<?php

namespace App\Models;

use Database\Factories\ScholarshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Program beasiswa (Tahfidz, Yatim, Anak Ustadz) beserta donaturnya. Lintas
 * jenjang. Mode dan nilai bawaan hanya mengisi keringanan baru; keringanan
 * yang sudah ada menyimpan mode dan nilainya sendiri. Tidak dihapus, cukup
 * dinonaktifkan. Mode memakai konstanta `FeeOverride::MODES`.
 */
#[Fillable(['name', 'mode', 'default_value', 'sponsor', 'is_active'])]
class Scholarship extends Model
{
    /** @use HasFactory<ScholarshipFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'default_value' => 'integer',
            'is_active' => 'boolean',
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

    public function feeOverrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Teks keringanan bawaan program, mis. "Potongan 50%".
     */
    public function defaultValueLabel(): string
    {
        return FeeOverride::formatValue($this->mode, $this->default_value);
    }
}

<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Syarat berkas PSB untuk satu jenjang atau semua jenjang (`unit_id` NULL).
 * Satu jenis dokumen paling banyak satu syarat per jenjang; syarat semua
 * jenjang dan per jenjang untuk jenis yang sama tidak berdampingan (dijaga
 * `SaveAdmissionRequirement`). Kolom generated `unit_key` hanya dipakai unique.
 *
 * Tanpa global scope cakupan jenjang karena form PSB publik (F010) membaca
 * syarat tanpa login; cakupan diterapkan di resource dan Action.
 */
#[Fillable(['unit_id', 'document_type_id', 'name', 'is_required', 'sort_order'])]
class AdmissionRequirement extends Model
{
    /** @use HasFactory<AdmissionRequirementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * Syarat untuk satu jenjang: syarat jenjang itu dan syarat semua jenjang,
     * urut `sort_order` lalu `id`.
     */
    public function scopeForUnit(Builder $query, int $unitId): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('unit_id')->orWhere('unit_id', $unitId))
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

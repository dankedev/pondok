<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\DiniyahSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mapel diniyah per jenjang (F027). Tidak dihapus; mapel nonaktif hilang dari lembar input
 * tetapi tetap tampil di rapor yang sudah bernilai. User login hanya melihat mapel jenjangnya.
 * KKM (`passing_score`) opsional (F096).
 */
#[Fillable(['unit_id', 'name', 'sort_order', 'is_active', 'passing_score'])]
class DiniyahSubject extends Model
{
    /** @use HasFactory<DiniyahSubjectFactory> */
    use HasFactory, HasUnitScope;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'passing_score' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(DiniyahGrade::class);
    }

    /**
     * Nilai di bawah KKM; nilai sama dengan KKM tuntas, mapel tanpa KKM tidak pernah ditandai.
     */
    public function isBelowPassing(?int $score): bool
    {
        return $score !== null && $this->passing_score !== null && $score < $this->passing_score;
    }

    /**
     * @param  Builder<DiniyahSubject>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

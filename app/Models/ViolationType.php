<?php

namespace App\Models;

use App\Enums\ViolationCategory;
use Database\Factories\ViolationTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis pelanggaran berpoin (F026), disimpan lewat `App\Actions\Kedisiplinan\SaveViolationType`.
 * Tidak dihapus; jenis nonaktif tidak bisa dipilih untuk catatan baru.
 */
#[Fillable(['name', 'category', 'points', 'is_active'])]
class ViolationType extends Model
{
    /** @use HasFactory<ViolationTypeFactory> */
    use HasFactory;

    public const MAX_POINTS = 1000;

    protected function casts(): array
    {
        return [
            'category' => ViolationCategory::class,
            'points' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function violations(): HasMany
    {
        return $this->hasMany(StudentViolation::class);
    }

    /**
     * @param  Builder<ViolationType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

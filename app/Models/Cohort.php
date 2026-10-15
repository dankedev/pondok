<?php

namespace App\Models;

use Database\Factories\CohortFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Angkatan: kelompok santri berdasarkan tahun masuk per jenjang.
 */
#[Fillable(['unit_id', 'entry_year', 'name'])]
class Cohort extends Model
{
    /** @use HasFactory<CohortFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'entry_year' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}

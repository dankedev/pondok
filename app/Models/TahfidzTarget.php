<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target hafalan per tahun ajaran, jenjang, dan tingkat. `grade` kosong = semua tingkat.
 */
#[Fillable(['academic_year_id', 'unit_id', 'grade', 'target_juz'])]
class TahfidzTarget extends Model
{
    use HasUnitScope;

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'target_juz' => 'decimal:1',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\DisciplineLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ambang poin kedisiplinan (F026), mis. "Peringatan 1" mulai 25 poin. Hanya label di rekap:
 * level santri = ambang dengan `min_points` terbesar yang ≤ total poinnya. Tidak disimpan per santri.
 */
#[Fillable(['name', 'min_points'])]
class DisciplineLevel extends Model
{
    /** @use HasFactory<DisciplineLevelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_points' => 'integer',
        ];
    }
}

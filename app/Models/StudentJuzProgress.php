<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capaian hafalan per juz (PK komposit `student_id` + `juz`). Hanya ditulis `RefreshJuzProgress`;
 * juz tanpa baris berarti belum.
 */
class StudentJuzProgress extends Model
{
    public const STATUSES = ['proses', 'hafal', 'lulus_ujian'];

    /** Warna badge capaian juz (design-guide §4); juz tanpa baris (belum) memakai `gray`. */
    public const STATUS_COLORS = [
        'proses' => 'warning',
        'hafal' => 'info',
        'lulus_ujian' => 'success',
    ];

    /** Status yang dihitung sebagai juz hafal. */
    public const MEMORIZED = ['hafal', 'lulus_ujian'];

    protected $table = 'student_juz_progress';

    protected $primaryKey = 'student_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'juz' => 'integer',
            'completed_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}

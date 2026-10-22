<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHalaqah;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ujian tahfidz. Hasil `lulus` menandai juz rentangnya `lulus_ujian`.
 */
#[Fillable(['student_id', 'halaqah_id', 'kind', 'juz_from', 'juz_to', 'exam_on', 'examiner_staff_id', 'score', 'result', 'certificate_no'])]
class TahfidzExam extends Model
{
    use BelongsToHalaqah;

    public const KINDS = ['kenaikan_juz', 'tasmi_5_juz', 'khataman'];

    public const RESULTS = ['lulus', 'ulang'];

    public const RESULT_PASSED = 'lulus';

    /** Warna badge hasil ujian (design-guide §4). */
    public const RESULT_COLORS = [
        'lulus' => 'success',
        'ulang' => 'warning',
    ];

    protected function casts(): array
    {
        return [
            'exam_on' => 'date',
            'juz_from' => 'integer',
            'juz_to' => 'integer',
            'score' => 'integer',
        ];
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'examiner_staff_id');
    }

    public function juzLabel(): string
    {
        return $this->juz_from === $this->juz_to
            ? __('pondok.tahfidz.exam.juz_single', ['from' => $this->juz_from])
            : __('pondok.tahfidz.exam.juz_range', ['from' => $this->juz_from, 'to' => $this->juz_to]);
    }
}

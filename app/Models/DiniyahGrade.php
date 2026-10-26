<?php

namespace App\Models;

use Database\Factories\DiniyahGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai satu mapel diniyah seorang santri pada satu semester (F027), disimpan lewat
 * `App\Actions\Akademik\SaveDiniyahGrades`. Predikat dihitung dari `score`, tidak disimpan.
 */
#[Fillable(['student_id', 'academic_year_id', 'semester', 'diniyah_subject_id', 'score', 'recorded_by'])]
class DiniyahGrade extends Model
{
    /** @use HasFactory<DiniyahGradeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'score' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(DiniyahSubject::class, 'diniyah_subject_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

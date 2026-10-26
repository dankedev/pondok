<?php

namespace App\Models;

use Database\Factories\DiniyahReportNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan wali kelas pada rapor diniyah satu santri per semester (F027).
 */
#[Fillable(['student_id', 'academic_year_id', 'semester', 'note', 'recorded_by'])]
class DiniyahReportNote extends Model
{
    /** @use HasFactory<DiniyahReportNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

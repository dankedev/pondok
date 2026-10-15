<?php

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rombel per tahun ajaran, misalnya 1A, 1B, 1C.
 */
#[Fillable(['academic_year_id', 'unit_id', 'grade', 'name', 'gender', 'homeroom_staff_id', 'capacity'])]
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory;

    public const GENDER_MIXED = 'campur';

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'capacity' => 'integer',
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

    public function homeroomStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'homeroom_staff_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function acceptsGender(string $gender): bool
    {
        return $this->gender === self::GENDER_MIXED || $this->gender === $gender;
    }
}

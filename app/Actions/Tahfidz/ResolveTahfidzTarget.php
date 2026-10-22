<?php

namespace App\Actions\Tahfidz;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\TahfidzTarget;

/**
 * Target hafalan santri di tahun ajaran aktif: target tingkat rombelnya, lalu target semua
 * tingkat di jenjangnya, selain itu null.
 */
class ResolveTahfidzTarget
{
    public function handle(Student $student): ?TahfidzTarget
    {
        $year = AcademicYear::current();

        if ($year === null) {
            return null;
        }

        $grade = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->first()?->classroom?->grade;

        return TahfidzTarget::withoutUnitScope()
            ->where('academic_year_id', $year->id)
            ->where('unit_id', $student->unit_id)
            ->where(fn ($query) => $query->whereNull('grade')->when($grade !== null, fn ($q) => $q->orWhere('grade', $grade)))
            ->get()
            ->sortBy(fn (TahfidzTarget $target): int => $target->grade === null ? 1 : 0)
            ->first();
    }
}

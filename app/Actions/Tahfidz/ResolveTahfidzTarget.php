<?php

namespace App\Actions\Tahfidz;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\TahfidzTarget;
use Illuminate\Support\Collection;

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

        $targets = TahfidzTarget::withoutUnitScope()
            ->where('academic_year_id', $year->id)
            ->where('unit_id', $student->unit_id)
            ->get();

        return self::pick($targets, $student->unit_id, $grade);
    }

    /**
     * Pilih target dari daftar target satu tahun ajaran yang sudah dimuat (dipakai tabel anggota
     * halaqah agar tidak query per santri).
     *
     * @param  Collection<int, TahfidzTarget>  $targets
     */
    public static function pick(Collection $targets, ?int $unitId, ?int $grade): ?TahfidzTarget
    {
        $inUnit = $targets->where('unit_id', $unitId);

        return ($grade !== null ? $inUnit->firstWhere('grade', $grade) : null)
            ?? $inUnit->first(fn (TahfidzTarget $target): bool => $target->grade === null);
    }
}

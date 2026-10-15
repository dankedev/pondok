<?php

namespace App\Actions\Akademik;

use App\Models\Cohort;
use App\Models\Student;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

/**
 * Memindahkan santri ke jenjang lain, misalnya lanjut dari SD ke SLTP.
 *
 * Riwayat jenjang lama tetap terbaca dari `enrollments`. Penempatan rombel
 * di jenjang baru dilakukan terpisah lewat `EnrollStudent`.
 */
class ChangeStudentUnit
{
    public function handle(Student $student, Unit $unit, Cohort $cohort): Student
    {
        if ($cohort->unit_id !== $unit->id) {
            throw ValidationException::withMessages([
                'cohort_id' => __('pondok.akademik.errors.cohort_unit_mismatch'),
            ]);
        }

        $student->forceFill([
            'unit_id' => $unit->id,
            'cohort_id' => $cohort->id,
        ])->save();

        return $student;
    }
}

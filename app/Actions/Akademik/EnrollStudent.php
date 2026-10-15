<?php

namespace App\Actions\Akademik;

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menempatkan santri ke rombel pada tahun ajaran rombel tersebut.
 *
 * Satu santri hanya punya satu rombel per tahun ajaran. Jika santri sudah
 * punya rombel di tahun itu, rombelnya dipindah (mutasi antar paralel).
 */
class EnrollStudent
{
    public function handle(Student $student, Classroom $classroom): Enrollment
    {
        if (! $student->isActive()) {
            $this->fail('student_inactive', $student);
        }

        if ($classroom->unit_id !== $student->unit_id) {
            $this->fail('classroom_unit_mismatch', $student);
        }

        if (! $classroom->acceptsGender($student->gender)) {
            $this->fail('classroom_gender_mismatch', $student);
        }

        return DB::transaction(function () use ($student, $classroom): Enrollment {
            $existing = Enrollment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $classroom->academic_year_id)
                ->lockForUpdate()
                ->first();

            if ($existing?->classroom_id === $classroom->id) {
                return $existing;
            }

            if ($classroom->capacity !== null) {
                $occupied = Enrollment::query()
                    ->where('classroom_id', $classroom->id)
                    ->lockForUpdate()
                    ->count();

                if ($occupied >= $classroom->capacity) {
                    $this->fail('classroom_full', $student);
                }
            }

            if ($existing !== null) {
                $existing->update(['classroom_id' => $classroom->id]);

                return $existing;
            }

            return Enrollment::query()->create([
                'student_id' => $student->id,
                'classroom_id' => $classroom->id,
                'academic_year_id' => $classroom->academic_year_id,
            ]);
        });
    }

    private function fail(string $reason, Student $student): never
    {
        throw ValidationException::withMessages([
            'classroom_id' => __("pondok.akademik.errors.{$reason}", ['name' => $student->name]),
        ]);
    }
}

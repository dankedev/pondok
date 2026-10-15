<?php

namespace App\Actions\Akademik;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

/**
 * Kenaikan kelas: memindahkan santri aktif dari rombel asal ke rombel di
 * tahun ajaran berikutnya. Riwayat lama tidak diubah dan aman dijalankan ulang.
 */
class PromoteStudents
{
    public function __construct(private EnrollStudent $enrollStudent) {}

    /**
     * @param  list<int>|null  $studentIds  Batasi ke santri tertentu; null berarti semua santri aktif di rombel asal.
     * @return array{promoted: int, skipped: list<string>}
     */
    public function handle(Classroom $from, Classroom $to, ?array $studentIds = null): array
    {
        $this->ensurePromotable($from, $to);

        $students = Student::query()
            ->active()
            ->whereIn('id', Enrollment::query()->select('student_id')->where('classroom_id', $from->id))
            ->when($studentIds !== null, fn ($query) => $query->whereKey($studentIds))
            ->orderBy('name')
            ->get();

        $alreadyEnrolled = Enrollment::query()
            ->where('academic_year_id', $to->academic_year_id)
            ->whereIn('student_id', $students->modelKeys())
            ->pluck('student_id')
            ->all();

        $promoted = 0;
        $skipped = [];

        foreach ($students as $student) {
            if (in_array($student->id, $alreadyEnrolled, true)) {
                continue;
            }

            try {
                $this->enrollStudent->handle($student, $to);
                $promoted++;
            } catch (ValidationException $exception) {
                $skipped[] = $exception->validator->errors()->first();
            }
        }

        return ['promoted' => $promoted, 'skipped' => $skipped];
    }

    private function ensurePromotable(Classroom $from, Classroom $to): void
    {
        if ($from->unit_id !== $to->unit_id) {
            throw ValidationException::withMessages([
                'target_classroom_id' => __('pondok.akademik.errors.promotion_unit_mismatch'),
            ]);
        }

        $fromYear = AcademicYear::query()->findOrFail($from->academic_year_id);
        $toYear = AcademicYear::query()->findOrFail($to->academic_year_id);

        if (! $toYear->starts_on->greaterThan($fromYear->starts_on)) {
            throw ValidationException::withMessages([
                'target_classroom_id' => __('pondok.akademik.errors.promotion_year_not_later'),
            ]);
        }
    }
}

<?php

namespace App\Actions\Akademik;

use App\Enums\DiniyahPredicate;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\DiniyahSubject;
use App\Models\Enrollment;
use App\Models\Staff;
use App\Models\Student;

/**
 * Menyusun rapor diniyah satu santri pada satu semester (F027). Hanya membaca; tanpa pemeriksaan akses
 * (pemanggil yang memeriksa). Dipakai bersama cetak admin dan portal wali.
 *
 * Jenjang mapel diambil dari rombel pada enrollment tahun itu (bukan `students.unit_id` sekarang). Baris
 * = mapel aktif jenjang itu ditambah mapel nonaktif yang sudah bernilai, urut `sort_order`. Rata-rata
 * hanya dari nilai terisi (`null` bila tidak ada). Tanpa peringkat.
 */
class BuildDiniyahReportCard
{
    /**
     * @return array{student: Student, year: AcademicYear, semester: int, classroom: ?Classroom, homeroom: ?Staff, rows: list<array{subject: string, score: ?int, predicate: ?DiniyahPredicate, passing_score: ?int, below_passing: bool}>, average: ?float, note: ?string}
     */
    public function handle(Student $student, AcademicYear $year, int $semester): array
    {
        $classroom = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->first()
            ?->classroom()->with('homeroomStaff')->first();

        $scores = DiniyahGrade::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->where('semester', $semester)
            ->pluck('score', 'diniyah_subject_id');

        $rows = DiniyahSubject::withoutUnitScope()
            ->where(function ($query) use ($classroom, $scores): void {
                $query->whereIn('id', $scores->keys());

                if ($classroom !== null) {
                    $query->orWhere(fn ($active) => $active->where('unit_id', $classroom->unit_id)->where('is_active', true));
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (DiniyahSubject $subject) use ($scores): array {
                $score = $scores->get($subject->id);

                return [
                    'subject' => $subject->name,
                    'score' => $score,
                    'predicate' => $score === null ? null : DiniyahPredicate::fromScore($score),
                    'passing_score' => $subject->passing_score,
                    'below_passing' => $subject->isBelowPassing($score),
                ];
            })
            ->values();

        return [
            'student' => $student,
            'year' => $year,
            'semester' => $semester,
            'classroom' => $classroom,
            'homeroom' => $classroom?->homeroomStaff,
            'rows' => $rows->all(),
            'average' => $scores->isEmpty() ? null : (float) $scores->avg(),
            'note' => DiniyahReportNote::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $year->id)
                ->where('semester', $semester)
                ->value('note'),
        ];
    }
}

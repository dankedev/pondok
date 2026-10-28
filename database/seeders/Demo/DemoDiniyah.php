<?php

namespace Database\Seeders\Demo;

use App\Actions\Akademik\SaveDiniyahGrades;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahSubject;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Mapel diniyah per jenjang demo (sebagian ber-KKM, F096) dan nilai semester 1 tahun ajaran aktif untuk semua
 * rombel (F027), disimpan lewat `SaveDiniyahGrades` oleh super-admin demo. Nilai dibuat deterministik dari
 * NIS sehingga beberapa santri di bawah KKM. Mapel dicari menurut nama; nilai hanya dibuat bila belum ada
 * nilai sama sekali (idempotent).
 */
final class DemoDiniyah
{
    /**
     * Mapel: [nama, KKM|null].
     */
    private const SUBJECTS = [
        ['Aqidah', 70],
        ['Fiqih', 70],
        ['Hadits', 65],
        ['Bahasa Arab', null],
        ['Tahsin', 75],
        ['Sirah Nabawiyah', null],
    ];

    private const SEMESTER = 1;

    private const NOTE = 'Adab dan kesungguhan belajar baik. Tingkatkan murajaah hafalan hadits dan kosakata bahasa Arab.';

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        foreach (array_keys(DemoData::UNIT_NAMES) as $code) {
            foreach (self::SUBJECTS as $index => [$name, $passingScore]) {
                DiniyahSubject::withoutUnitScope()->firstOrCreate(
                    ['unit_id' => $this->context->unit($code)->id, 'name' => $name],
                    ['sort_order' => $index + 1, 'passing_score' => $passingScore, 'is_active' => true],
                );
            }
        }

        if (DiniyahGrade::query()->exists()) {
            return;
        }

        $actor = $this->context->user('super-admin');
        $classrooms = Classroom::query()
            ->where('academic_year_id', $this->context->activeYear->id)
            ->orderBy('unit_id')
            ->orderBy('name')
            ->get();

        foreach ($classrooms as $classroom) {
            $students = $classroom->rosterStudents()->get();
            $subjects = DiniyahSubject::withoutUnitScope()->active()->where('unit_id', $classroom->unit_id)->get();

            if ($students->isEmpty()) {
                $this->context->skip('nilai diniyah', "rombel {$classroom->name} tanpa santri");

                continue;
            }

            app(SaveDiniyahGrades::class)->handle(
                $actor,
                $classroom,
                self::SEMESTER,
                $this->scores($students, $subjects),
                [$students->first()->id => self::NOTE],
            );
        }
    }

    /**
     * Nilai 58–98 dari NIS dan nama mapel.
     *
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, DiniyahSubject>  $subjects
     * @return array<int, array<int, int>>
     */
    private function scores(Collection $students, Collection $subjects): array
    {
        return $students->mapWithKeys(fn (Student $student): array => [
            $student->id => $subjects->mapWithKeys(fn (DiniyahSubject $subject): array => [
                $subject->id => 58 + crc32($student->nis.$subject->name) % 41,
            ])->all(),
        ])->all();
    }
}

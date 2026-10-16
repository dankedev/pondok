<?php

namespace Database\Seeders\Demo;

use App\Actions\Akademik\AttachGuardian;
use App\Actions\Akademik\ChangeStudentUnit;
use App\Actions\Akademik\CopyClassroomsFromYear;
use App\Actions\Akademik\CreateStudent;
use App\Actions\Akademik\EnrollStudent;
use App\Actions\Akademik\FindOrCreateCohort;
use App\Actions\Akademik\PromoteStudents;
use App\Actions\Asrama\AssignRoom;
use App\Actions\Asrama\AssignSupervisor;
use App\Actions\Asrama\SaveRoom;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Rombel, santri, wali, riwayat kelas, asrama, dan pembina asrama demo.
 */
final class DemoAcademics
{
    /** Alasan pindah kamar satu santri putra. */
    public const ROOM_MOVE_NOTE = 'Pindah kamar agar dekat dengan kelompok halaqah tahfidz';

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $this->seedPreviousClassrooms();
        $this->seedStudents();
        app(CopyClassroomsFromYear::class)->handle($this->context->previousYear, $this->context->activeYear);
        $this->assignActiveHomerooms();
        $this->moveStudentsToActiveYear();
        $this->seedDormitories();
    }

    private function classroom(AcademicYear $year, string $unitCode, string $name): Classroom
    {
        return Classroom::query()
            ->where('academic_year_id', $year->id)
            ->where('unit_id', $this->context->unit($unitCode)->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @return array<string, string> "JENJANG:rombel" => NIP wali kelas
     */
    private function homerooms(): array
    {
        $map = [];

        foreach (DemoData::STAFF as $row) {
            if (isset($row['homeroom'])) {
                $map[$row['homeroom']] = $row['nip'];
            }
        }

        return $map;
    }

    private function seedPreviousClassrooms(): void
    {
        $homerooms = $this->homerooms();

        foreach (DemoData::CLASSROOMS as $unitCode => $classrooms) {
            foreach ($classrooms as $row) {
                Classroom::query()->firstOrCreate([
                    'academic_year_id' => $this->context->previousYear->id,
                    'unit_id' => $this->context->unit($unitCode)->id,
                    'name' => $row['name'],
                ], [
                    'grade' => $row['grade'],
                    'gender' => $row['gender'],
                    'capacity' => 24,
                    'homeroom_staff_id' => $this->context->staff[$homerooms["{$unitCode}:{$row['name']}"]]->id,
                ]);
            }
        }
    }

    private function assignActiveHomerooms(): void
    {
        foreach ($this->homerooms() as $key => $nip) {
            [$unitCode, $name] = explode(':', $key);
            $classroom = $this->classroom($this->context->activeYear, $unitCode, $name);

            if ($classroom->homeroom_staff_id === null) {
                $classroom->update(['homeroom_staff_id' => $this->context->staff[$nip]->id]);
            }
        }
    }

    /**
     * Nama rombel tujuan menurut tingkat dan jenis kelamin santri.
     */
    private function classroomName(string $unitCode, int $grade, string $gender): string
    {
        return $grade.($unitCode === 'SD' || $gender === 'L' ? 'A' : 'B');
    }

    private function seedStudents(): void
    {
        $calendar = $this->context->calendar;
        $cohorts = app(FindOrCreateCohort::class);

        foreach (DemoData::students() as $row) {
            $nis = $calendar->nis($row['entry_offset'], $row['seq']);

            if (Student::withoutUnitScope()->withTrashed()->where('nis', $nis)->exists()) {
                continue;
            }

            $unit = $this->context->unit($row['origin_unit']);
            $entryYear = $calendar->entryYear($row['entry_offset']);
            $guardianData = DemoData::guardian($row['family']);

            $student = DB::transaction(function () use ($row, $nis, $unit, $entryYear, $cohorts, $calendar, $guardianData): Student {
                $student = app(CreateStudent::class)->handle([
                    'unit_id' => $unit->id,
                    'cohort_id' => $cohorts->handle($unit, $entryYear)->id,
                    'nis' => $nis,
                    'name' => $row['name'],
                    'gender' => $row['gender'],
                    'birth_place' => $row['birth_place'],
                    'birth_date' => $calendar->birthDate($entryYear - DemoData::ENTRY_AGE[$row['origin_unit']], $row['birth_md']),
                    'address' => $guardianData['address'],
                    'status' => Student::STATUS_ACTIVE,
                    'entered_on' => $calendar->enteredOn($row['entry_offset']),
                ]);

                $guardian = Guardian::query()->firstOrCreate(['phone' => $guardianData['phone']], [
                    'name' => $guardianData['name'],
                    'occupation' => $guardianData['occupation'],
                    'address' => $guardianData['address'],
                ]);

                if (! $student->guardians()->whereKey($guardian->id)->exists()) {
                    app(AttachGuardian::class)->handle($student, $guardian, $guardianData['relation'], isBillingContact: true);
                }

                if ($row['p'] !== null) {
                    [$unitCode, $grade] = $row['p'];
                    $classroom = $this->classroom($this->context->previousYear, $unitCode, $this->classroomName($unitCode, $grade, $row['gender']));
                    app(EnrollStudent::class)->handle($student, $classroom);
                }

                if ($row['status'] !== Student::STATUS_ACTIVE) {
                    $student->update(['status' => $row['status']]);
                }

                return $student;
            });

            unset($student);
        }
    }

    /**
     * Kenaikan kelas (PromoteStudents), pindah jenjang (ChangeStudentUnit), dan santri baru.
     */
    private function moveStudentsToActiveYear(): void
    {
        $promotions = [];

        foreach (DemoData::students() as $row) {
            if ($row['a'] === null) {
                continue;
            }

            $student = $this->context->student($row['name']);

            if (Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $this->context->activeYear->id)->exists()) {
                continue;
            }

            [$unitCode, $grade] = $row['a'];
            $target = $this->classroom($this->context->activeYear, $unitCode, $this->classroomName($unitCode, $grade, $row['gender']));

            if ($row['p'] !== null && $row['p'][0] === $unitCode) {
                $from = $this->classroom($this->context->previousYear, $row['p'][0], $this->classroomName($row['p'][0], $row['p'][1], $row['gender']));
                $promotions["{$from->id}:{$target->id}"]['from'] = $from;
                $promotions["{$from->id}:{$target->id}"]['to'] = $target;
                $promotions["{$from->id}:{$target->id}"]['ids'][] = $student->id;

                continue;
            }

            if ($student->unit_id !== $target->unit_id) {
                $student = app(ChangeStudentUnit::class)->handle(
                    $student,
                    $this->context->unit($unitCode),
                    app(FindOrCreateCohort::class)->handle($this->context->unit($unitCode), $this->context->calendar->year),
                );
            }

            app(EnrollStudent::class)->handle($student, $target);
        }

        foreach ($promotions as $promotion) {
            app(PromoteStudents::class)->handle($promotion['from'], $promotion['to'], $promotion['ids']);
        }
    }

    private function seedDormitories(): void
    {
        $activeYear = $this->context->activeYear;
        $startOn = $activeYear->starts_on->toImmutable();
        $rooms = [];

        foreach (DemoData::DORMITORIES as $row) {
            $dormitory = Dormitory::query()->firstOrCreate(['code' => $row['code']], [
                'name' => $row['name'],
                'gender' => $row['gender'],
                'location' => $row['location'],
                'is_active' => true,
            ]);

            foreach ($row['rooms'] as $name) {
                $room = Room::query()->where('dormitory_id', $dormitory->id)->where('name', $name)->first()
                    ?? app(SaveRoom::class)->handle($dormitory, ['name' => $name, 'floor' => $row['floor'], 'capacity' => DemoData::ROOM_CAPACITY]);
                $rooms[$row['gender']][] = $room;
            }

            $this->seedSupervisors($dormitory, $rooms[$row['gender']][0]);
        }

        $counters = ['L' => 0, 'P' => 0];
        $firstMale = null;

        foreach (DemoData::students() as $row) {
            if ($row['a'] === null || $row['a'][0] === 'SD' || in_array('pulang', $row['flags'], true)) {
                continue;
            }

            $gender = $row['gender'];
            $room = $rooms[$gender][$counters[$gender]++ % count($rooms[$gender])];
            $student = $this->context->student($row['name']);
            $firstMale ??= $gender === 'L' ? ['student' => $student, 'room' => $room] : null;

            if (! RoomAssignment::withoutUnitScope()->where('student_id', $student->id)->exists()) {
                app(AssignRoom::class)->handle($student, $room, $startOn);
            }
        }

        $this->moveRoom($firstMale['student'], $rooms['L'][count($rooms['L']) - 1]);
    }

    /**
     * Satu santri putra pindah kamar sehingga punya riwayat dua baris.
     */
    private function moveRoom(Student $student, Room $target): void
    {
        $rows = RoomAssignment::withoutUnitScope()->where('student_id', $student->id)->get();

        if ($rows->count() >= 2 || $rows->contains(fn (RoomAssignment $row): bool => $row->end_on === null && $row->room_id === $target->id)) {
            return;
        }

        $startOn = $this->context->activeYear->starts_on->toImmutable()->addDay();
        $recent = $this->context->calendar->today->subDays(30);
        $startOn = $startOn->max($recent);

        if ($startOn->gt($this->context->calendar->today)) {
            $this->context->skip('pindah kamar', 'tanggal pindah melewati hari ini');

            return;
        }

        app(AssignRoom::class)->handle($student, $target, $startOn, self::ROOM_MOVE_NOTE);
    }

    private function seedSupervisors(Dormitory $dormitory, Room $firstRoom): void
    {
        [$first, $second] = $this->context->staffByPosition('musyrif', $dormitory->gender);
        $year = $this->context->activeYear;
        $startOn = $year->starts_on->toImmutable();
        $assign = app(AssignSupervisor::class);

        $hasHead = DormitorySupervisor::query()
            ->where('dormitory_id', $dormitory->id)
            ->where('academic_year_id', $year->id)
            ->where('role', DormitorySupervisor::ROLE_HEAD)
            ->whereNull('end_on')
            ->exists();

        if (! $hasHead) {
            $assign->handle($dormitory, $first, DormitorySupervisor::ROLE_HEAD, $year, null, $startOn);
        }

        $assign->handle($dormitory, $second, DormitorySupervisor::ROLE_SUPERVISOR, $year, null, $startOn);
        $assign->handle($dormitory, $first, DormitorySupervisor::ROLE_SUPERVISOR, $year, $firstRoom, $startOn);
    }
}

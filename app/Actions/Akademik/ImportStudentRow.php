<?php

namespace App\Actions\Akademik;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Unit;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mengimpor satu baris template santri: santri (upsert menurut NIS), angkatan,
 * rombel opsional, dan hingga dua wali. Baris yang gagal dibatalkan utuh.
 */
class ImportStudentRow
{
    public function __construct(
        private CreateStudent $createStudent,
        private FindOrCreateCohort $findOrCreateCohort,
        private EnrollStudent $enrollStudent,
        private AttachGuardian $attachGuardian,
        private SetBillingContact $setBillingContact,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws RowImportFailedException
     */
    public function handle(array $row): Student
    {
        return DB::transaction(function () use ($row): Student {
            $unit = $this->resolveUnit((string) $row['unit']);
            $guardians = $this->guardianInputs($row);

            $student = $this->saveStudent($row, $unit);

            $this->enroll($student, trim((string) ($row['classroom'] ?? '')));
            $this->attachGuardians($student, $guardians);

            return $student;
        });
    }

    private function resolveUnit(string $value): Unit
    {
        $value = mb_strtolower(trim($value));

        $unit = Unit::query()->whereRaw('lower(code) = ?', [$value])->first();

        if ($unit === null) {
            $byName = Unit::query()->whereRaw('lower(name) = ?', [$value])->get();
            $unit = $byName->count() === 1 ? $byName->first() : null;
        }

        if ($unit === null) {
            $this->fail('unit_unknown');
        }

        $scoped = Auth::user()?->scopedUnitIds() ?? [];

        if ($scoped !== [] && ! in_array($unit->id, $scoped, true)) {
            $this->fail('unit_out_of_scope');
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function saveStudent(array $row, Unit $unit): Student
    {
        $cohort = $this->findOrCreateCohort->handle($unit, (int) $row['entry_year']);
        $attributes = [
            'name' => trim((string) $row['name']),
            'gender' => $row['gender'],
            'birth_place' => trim((string) $row['birth_place']),
            'birth_date' => $row['birth_date'],
            'cohort_id' => $cohort->id,
        ];

        $existing = Student::withoutUnitScope()->withTrashed()->where('nis', trim((string) $row['nis']))->first();

        if ($existing === null) {
            return $this->createStudent->handle([
                ...$attributes,
                'nis' => trim((string) $row['nis']),
                'unit_id' => $unit->id,
                'status' => Student::STATUS_ACTIVE,
                'entered_on' => "{$cohort->entry_year}-07-01",
            ]);
        }

        if ($existing->trashed()) {
            $this->fail('nis_deleted');
        }

        if ($existing->unit_id !== $unit->id) {
            $this->fail('nis_other_unit');
        }

        $existing->update($attributes);

        return $existing;
    }

    private function enroll(Student $student, string $classroomName): void
    {
        if ($classroomName === '') {
            return;
        }

        $year = AcademicYear::active()->first();

        if ($year === null) {
            $this->fail('no_active_academic_year');
        }

        $classroom = Classroom::query()
            ->where('academic_year_id', $year->id)
            ->where('unit_id', $student->unit_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($classroomName)])
            ->first();

        if ($classroom === null) {
            $this->fail('classroom_unknown', ['name' => $classroomName]);
        }

        $this->enrollStudent->handle($student, $classroom);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{name: string, relation: string, phone: string, billing: bool}>
     */
    private function guardianInputs(array $row): array
    {
        $inputs = [];

        foreach ([1, 2] as $number) {
            $name = trim((string) ($row["guardian{$number}_name"] ?? ''));
            $phone = trim((string) ($row["guardian{$number}_phone"] ?? ''));

            if ($name === '' && $phone === '') {
                continue;
            }

            $relation = mb_strtolower(trim((string) ($row["guardian{$number}_relation"] ?? '')));
            $normalized = $this->normalizePhone($phone);

            if ($name === '' || $normalized === null) {
                $this->fail('guardian_incomplete', ['number' => $number]);
            }

            if (! in_array($relation, Guardian::RELATIONS, true)) {
                $this->fail('guardian_relation_invalid', ['number' => $number]);
            }

            $inputs[] = [
                'name' => $name,
                'relation' => $relation,
                'phone' => $normalized,
                'billing' => mb_strtolower(trim((string) ($row["guardian{$number}_billing"] ?? ''))) === 'ya',
            ];
        }

        return $inputs;
    }

    /**
     * Wali pertama menjadi kontak tagihan kecuali wali kedua satu-satunya yang ditandai "ya".
     *
     * @param  list<array{name: string, relation: string, phone: string, billing: bool}>  $inputs
     */
    private function attachGuardians(Student $student, array $inputs): void
    {
        foreach ($inputs as $index => $input) {
            $guardian = $this->findOrCreateGuardian($input['name'], $input['phone']);
            $isBilling = $input['billing'] && ($index === 0 || ! $inputs[0]['billing']);

            if ($student->guardians()->whereKey($guardian->id)->exists()) {
                if ($isBilling) {
                    $this->setBillingContact->handle($student, $guardian);
                }

                continue;
            }

            $this->attachGuardian->handle($student, $guardian, $input['relation'], $isBilling);
        }
    }

    private function findOrCreateGuardian(string $name, string $phone): Guardian
    {
        $guardian = Guardian::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->get()
            ->first(fn (Guardian $candidate): bool => $this->normalizePhone((string) $candidate->phone) === $phone);

        return $guardian ?? Guardian::query()->create(['name' => $name, 'phone' => $phone]);
    }

    /**
     * Membuang spasi, tanda hubung, dan tanda kutip depan; awalan 62 menjadi 0.
     */
    private function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s\-]+/', '', ltrim(trim($phone), "'")) ?? '';
        $phone = Str::startsWith($phone, '+62') ? '0'.substr($phone, 3) : $phone;
        $phone = Str::startsWith($phone, '62') ? '0'.substr($phone, 2) : $phone;

        return preg_match('/^\d{8,15}$/', $phone) === 1 ? $phone : null;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $reason, array $replace = []): never
    {
        throw new RowImportFailedException(__("pondok.akademik.import.errors.{$reason}", $replace));
    }
}

<?php

use App\Actions\Akademik\ImportStudentRow;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(UnitSeeder::class);
    $this->actingAs(User::factory()->create());
});

/**
 * Baris template CSV yang sudah dicast Importer (semua nilai teks).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function santriRow(array $overrides = []): array
{
    return [
        'nis' => '2026001',
        'name' => 'Ahmad Fauzan',
        'gender' => 'L',
        'birth_place' => 'Bandung',
        'birth_date' => '2012-03-15',
        'unit' => 'SLTP',
        'entry_year' => '2026',
        'classroom' => '',
        'guardian1_name' => 'Abdullah Rahman',
        'guardian1_relation' => 'ayah',
        'guardian1_phone' => '081234567890',
        'guardian1_billing' => 'ya',
        'guardian2_name' => '',
        'guardian2_relation' => '',
        'guardian2_phone' => '',
        'guardian2_billing' => '',
        ...$overrides,
    ];
}

function santriImport(array $overrides = []): Student
{
    return app(ImportStudentRow::class)->handle(santriRow($overrides));
}

/**
 * Baris harus ditolak dengan alasan berbahasa Indonesia (bukan kunci lang mentah).
 *
 * @param  array<string, mixed>  $overrides
 */
function santriImportFails(array $overrides = []): void
{
    try {
        santriImport($overrides);
    } catch (ValidationException|RowImportFailedException $exception) {
        $message = $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->implode(' ')
            : $exception->getMessage();

        expect($message)->not->toBe('')
            ->and($message)->not->toContain('pondok.');

        return;
    }

    test()->fail('Baris seharusnya gagal, tetapi berhasil diimpor.');
}

function santriUnitId(string $code): int
{
    return Unit::query()->where('code', $code)->value('id');
}

describe('AC-3: baris valid membuat santri', function () {
    it('creates an active student from unit code, case-insensitive', function () {
        $student = santriImport(['unit' => 'sltp']);

        expect($student->exists)->toBeTrue()
            ->and($student->status)->toBe('aktif')
            ->and($student->unit_id)->toBe(santriUnitId('SLTP'))
            ->and($student->cohort->entry_year)->toBe(2026)
            ->and($student->cohort->unit_id)->toBe($student->unit_id)
            ->and($student->entered_on->toDateString())->toBe('2026-07-01')
            ->and($student->nis)->toBe('2026001')
            ->and($student->name)->toBe('Ahmad Fauzan')
            ->and($student->gender)->toBe('L')
            ->and($student->birth_place)->toBe('Bandung')
            ->and($student->birth_date->toDateString())->toBe('2012-03-15');
    });

    it('resolves the unit by name case-insensitively', function () {
        $student = santriImport(['unit' => 'sekolah dasar']);

        expect($student->unit_id)->toBe(santriUnitId('SD'));
    });

    it('fails when the unit name matches more than one unit', function () {
        Unit::factory()->count(2)->create(['name' => 'Sekolah Ganda']);

        santriImportFails(['unit' => 'Sekolah Ganda']);

        expect(Student::query()->count())->toBe(0);
    });

    it('fails when the unit is unknown', function () {
        santriImportFails(['unit' => 'XYZ']);

        expect(Student::query()->count())->toBe(0);
    });
});

describe('AC-4: angkatan', function () {
    it('creates the cohort named Angkatan {year} when missing and shares it between rows', function () {
        santriImport(['nis' => '1']);
        santriImport(['nis' => '2', 'guardian1_name' => 'Lain', 'guardian1_phone' => '081111111111']);

        $cohorts = Cohort::query()->where('unit_id', santriUnitId('SLTP'))->where('entry_year', 2026)->get();

        expect($cohorts)->toHaveCount(1)
            ->and($cohorts->first()->name)->toBe('Angkatan 2026')
            ->and(Student::query()->where('cohort_id', $cohorts->first()->id)->count())->toBe(2);
    });

    it('reuses an existing cohort', function () {
        $cohort = Cohort::factory()->create(['unit_id' => santriUnitId('SLTP'), 'entry_year' => 2026, 'name' => 'Angkatan Perdana']);

        $student = santriImport();

        expect($student->cohort_id)->toBe($cohort->id)
            ->and(Cohort::query()->count())->toBe(1);
    });
});

describe('AC-5: wali', function () {
    it('shares one guardian between siblings, even with +62 versus 0 phone formats', function () {
        $first = santriImport(['nis' => '1', 'guardian1_phone' => '081234567890']);
        $second = santriImport(['nis' => '2', 'name' => 'Umar Fauzan', 'guardian1_name' => 'abdullah rahman ', 'guardian1_phone' => '+62 812-3456-7890']);

        expect(Guardian::query()->count())->toBe(1)
            ->and(Guardian::query()->first()->phone)->toBe('081234567890')
            ->and(DB::table('student_guardian')->count())->toBe(2)
            ->and($first->guardians()->first()->id)->toBe($second->guardians()->first()->id);
    });

    it('reuses a guardian created manually with an unnormalized phone', function () {
        Guardian::factory()->create(['name' => 'Abdullah Rahman', 'phone' => '+62 812-3456-7890']);

        santriImport(['guardian1_phone' => '081234567890']);

        expect(Guardian::query()->count())->toBe(1);
    });

    it('treats guardians with the same name but another phone as different people', function () {
        santriImport(['nis' => '1']);
        santriImport(['nis' => '2', 'guardian1_phone' => '089999999999']);

        expect(Guardian::query()->count())->toBe(2);
    });

    it('attaches both guardians with their relations and skips an empty guardian 2', function () {
        $student = santriImport([
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000',
        ]);
        $alone = santriImport(['nis' => '2', 'guardian1_name' => 'Sendiri', 'guardian1_phone' => '081333333333']);

        expect($student->guardians()->pluck('student_guardian.relation')->sort()->values()->all())->toBe(['ayah', 'ibu'])
            ->and($alone->guardians()->count())->toBe(1);
    });

    it('fails and stores nothing when guardian 2 has only a name or only a phone', function (array $partial) {
        santriImportFails($partial);

        expect(Student::query()->count())->toBe(0)
            ->and(Guardian::query()->count())->toBe(0)
            ->and(DB::table('student_guardian')->count())->toBe(0);
    })->with([
        'name only' => [['guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu']],
        'phone only' => [['guardian2_phone' => '081200000000', 'guardian2_relation' => 'ibu']],
    ]);

    it('makes guardian 1 the billing contact when both are marked ya', function () {
        $student = santriImport([
            'guardian1_billing' => 'ya',
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000', 'guardian2_billing' => 'ya',
        ]);

        $billing = DB::table('student_guardian')->where('student_id', $student->id)->where('is_billing_contact', true)->pluck('relation')->all();

        expect($billing)->toBe(['ayah']);
    });

    it('makes guardian 2 the billing contact when only guardian 2 is ya and guardian 1 is tidak', function () {
        $student = santriImport([
            'guardian1_billing' => 'tidak',
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000', 'guardian2_billing' => 'ya',
        ]);

        $billing = DB::table('student_guardian')->where('student_id', $student->id)->where('is_billing_contact', true)->pluck('relation')->all();

        expect($billing)->toBe(['ibu']);
    });

    it('follows AttachGuardian when the billing columns are empty: guardian 1 is the only billing contact', function () {
        $student = santriImport([
            'guardian1_billing' => '',
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000', 'guardian2_billing' => '',
        ]);

        $billing = DB::table('student_guardian')->where('student_id', $student->id)->where('is_billing_contact', true)->pluck('relation')->all();

        expect($billing)->toBe(['ayah']);
    });
});

describe('AC-6: idempotensi dan upsert NIS', function () {
    it('keeps every table stable when the same row is imported twice', function () {
        $row = [
            'classroom' => '1A',
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000',
        ];
        $year = AcademicYear::factory()->active()->create();
        Classroom::factory()->create(['academic_year_id' => $year->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A']);

        santriImport($row);
        $counts = fn (): array => [
            Student::query()->count(), Guardian::query()->count(), DB::table('student_guardian')->count(),
            Cohort::query()->count(), Enrollment::query()->count(),
        ];
        $before = $counts();
        santriImport($row);

        expect($before)->toBe([1, 2, 2, 1, 1])
            ->and($counts())->toBe($before);
    });

    it('updates the template columns of an existing student with the same NIS', function () {
        $first = santriImport();

        $second = santriImport(['name' => 'Ahmad Fauzan Hakim', 'gender' => 'L', 'birth_place' => 'Garut', 'birth_date' => '2012-04-01', 'entry_year' => '2025']);

        expect($second->id)->toBe($first->id)
            ->and($first->fresh())
            ->name->toBe('Ahmad Fauzan Hakim')
            ->birth_place->toBe('Garut')
            ->birth_date->toDateString()->toBe('2012-04-01')
            ->and($first->fresh()->cohort->entry_year)->toBe(2025)
            ->and(Student::query()->count())->toBe(1);
    });

    it('does not rewrite existing guardian relations on re-import but adds the missing guardian', function () {
        $student = santriImport(['guardian1_relation' => 'ayah']);

        santriImport([
            'guardian1_relation' => 'wali',
            'guardian2_name' => 'Khadijah Aisyah', 'guardian2_relation' => 'ibu', 'guardian2_phone' => '081200000000',
        ]);

        expect($student->guardians()->where('guardians.name', 'Abdullah Rahman')->first()->pivot->relation)->toBe('ayah')
            ->and($student->guardians()->count())->toBe(2);
    });

    it('fails when the NIS belongs to a student of another unit, without duplicating or changing it', function () {
        $existing = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => santriUnitId('SD'), 'entry_year' => 2026]))->create(['nis' => '2026001', 'name' => 'Nama Lama']);

        santriImportFails(['unit' => 'SLTP']);

        expect(Student::withoutUnitScope()->withTrashed()->count())->toBe(1)
            ->and($existing->fresh()->name)->toBe('Nama Lama')
            ->and($existing->fresh()->unit_id)->toBe(santriUnitId('SD'))
            ->and(Guardian::query()->count())->toBe(0);
    });

    it('fails when the NIS belongs to a soft-deleted student', function () {
        $existing = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => santriUnitId('SLTP'), 'entry_year' => 2026]))->create(['nis' => '2026001', 'name' => 'Nama Lama']);
        $existing->delete();

        santriImportFails();

        expect(Student::withoutUnitScope()->withTrashed()->count())->toBe(1)
            ->and(Student::withTrashed()->find($existing->id)->name)->toBe('Nama Lama');
    });
});

describe('AC-7: rombel', function () {
    it('enrolls the student in the classroom of the active academic year', function () {
        $active = AcademicYear::factory()->active()->create();
        $other = AcademicYear::factory()->create();
        $classroom = Classroom::factory()->create(['academic_year_id' => $active->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A']);
        Classroom::factory()->create(['academic_year_id' => $other->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A']);

        $student = santriImport(['classroom' => '1A']);

        expect($student->enrollments()->pluck('classroom_id')->all())->toBe([$classroom->id]);
    });

    it('succeeds without enrollment when the classroom is empty', function () {
        $student = santriImport(['classroom' => '']);

        expect($student->exists)->toBeTrue()
            ->and(Enrollment::query()->count())->toBe(0);
    });

    it('rolls the whole row back when the classroom is rejected', function (Closure $arrange) {
        $arrange();

        $students = Student::withoutUnitScope()->withTrashed()->count();
        $guardians = Guardian::query()->count();
        $links = DB::table('student_guardian')->count();
        $enrollments = Enrollment::query()->count();

        santriImportFails(['classroom' => '1A', 'gender' => 'L']);

        expect(Student::withoutUnitScope()->withTrashed()->count())->toBe($students)
            ->and(Guardian::query()->count())->toBe($guardians)
            ->and(DB::table('student_guardian')->count())->toBe($links)
            ->and(Enrollment::query()->count())->toBe($enrollments);
    })->with([
        'classroom does not exist' => [fn () => AcademicYear::factory()->active()->create()],
        'classroom is for the other gender' => [function () {
            Classroom::factory()->create(['academic_year_id' => AcademicYear::factory()->active()->create()->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A', 'gender' => 'P']);
        }],
        'classroom is full' => [function () {
            $classroom = Classroom::factory()->create(['academic_year_id' => AcademicYear::factory()->active()->create()->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A', 'capacity' => 1]);
            Enrollment::factory()->create(['classroom_id' => $classroom->id, 'academic_year_id' => $classroom->academic_year_id]);
        }],
        'there is no active academic year' => [function () {
            Classroom::factory()->create(['academic_year_id' => AcademicYear::factory()->create()->id, 'unit_id' => santriUnitId('SLTP'), 'name' => '1A']);
        }],
    ]);
});

describe('AC-9: cakupan jenjang', function () {
    it('fails for a unit outside the importer scope and succeeds inside it', function () {
        $user = User::factory()->create();
        $user->units()->attach(santriUnitId('SD'));
        $this->actingAs($user);

        santriImportFails(['nis' => '1', 'unit' => 'SLTP']);
        $student = santriImport(['nis' => '2', 'unit' => 'SD']);

        expect(Student::withoutUnitScope()->count())->toBe(1)
            ->and($student->unit_id)->toBe(santriUnitId('SD'))
            ->and(Guardian::query()->count())->toBe(1);
    });

    it('does not overwrite or duplicate a student of an out-of-scope unit with the same NIS', function () {
        $existing = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => santriUnitId('SLTP'), 'entry_year' => 2026]))->create(['nis' => '2026001', 'name' => 'Nama Lama']);
        $user = User::factory()->create();
        $user->units()->attach(santriUnitId('SD'));
        $this->actingAs($user);

        santriImportFails(['unit' => 'SD']);
        santriImportFails(['unit' => 'SLTP']);

        expect(Student::withoutUnitScope()->count())->toBe(1)
            ->and($existing->fresh()->name)->toBe('Nama Lama');
    });
});

describe('VERIFY: integritas data', function () {
    it('does not let a scoped importer update a student of another unit through the NIS', function () {
        $other = Student::factory()->create(['nis' => '555', 'name' => 'Nama Asli', 'unit_id' => santriUnitId('SLTP')]);
        $user = User::factory()->create();
        $user->units()->attach(santriUnitId('SD'));
        $this->actingAs($user);

        santriImportFails(['nis' => '555', 'unit' => 'SD', 'name' => 'Ditimpa']);

        expect(Student::withoutUnitScope()->find($other->id)->name)->toBe('Nama Asli');
    });

    it('keeps the status of an existing student when it is imported again', function () {
        santriImport();
        Student::query()->where('nis', '2026001')->update(['status' => 'lulus']);

        santriImport(['name' => 'Ahmad Fauzan Baru']);

        $student = Student::query()->where('nis', '2026001')->firstOrFail();
        expect($student->status)->not->toBe(Student::STATUS_ACTIVE)
            ->and($student->name)->toBe('Ahmad Fauzan Baru');
    });

    it('fails on an ambiguous unit name instead of picking one', function () {
        Unit::query()->whereIn('code', ['SD', 'SLTP'])->update(['name' => 'Nama Kembar']);

        santriImportFails(['unit' => 'Nama Kembar']);

        expect(Student::withoutUnitScope()->count())->toBe(0);
    });

    it('rejects a guardian phone that is not a phone number', function (string $phone) {
        santriImportFails(['guardian1_phone' => $phone]);

        expect(Student::query()->count())->toBe(0)
            ->and(Guardian::query()->count())->toBe(0);
    })->with(['letters' => 'abcdefgh', 'too short' => '0812', 'formula' => '=1+1']);

    it('does not duplicate a student when the same NIS appears twice in one file', function () {
        santriImport(['nis' => '1', 'unit' => 'SLTP']);
        santriImportFails(['nis' => '1', 'unit' => 'SD']);

        expect(Student::withoutUnitScope()->where('nis', '1')->count())->toBe(1);
    });
});

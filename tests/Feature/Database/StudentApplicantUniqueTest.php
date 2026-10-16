<?php

use App\Models\Applicant;
use App\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('AC-40: allows one student per applicant', function () {
    $indexes = collect(Schema::getIndexes('students'));

    expect($indexes->where('unique', true)->pluck('columns')->all())->toContain(['applicant_id'])->toContain(['nis'])->toContain(['nisn'])
        ->and($indexes->pluck('columns')->all())->toContain(['unit_id', 'status']);

    $applicant = Applicant::factory()->create();
    Student::factory()->create(['applicant_id' => $applicant->id]);

    expect(fn () => Student::factory()->create(['applicant_id' => $applicant->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(Student::withoutGlobalScopes()->where('applicant_id', $applicant->id)->count())->toBe(1);

    Student::factory()->count(3)->create(['applicant_id' => null]);

    expect(Student::withoutGlobalScopes()->whereNull('applicant_id')->count())->toBe(3);
});

it('AC-40: keeps the students foreign keys after adding the unique index', function () {
    $keys = collect(Schema::getForeignKeys('students'));

    foreach (['unit_id' => 'units', 'cohort_id' => 'cohorts', 'applicant_id' => 'applicants'] as $column => $table) {
        $key = $keys->first(fn (array $key): bool => $key['columns'] === [$column]);

        expect($key)->not->toBeNull("FK {$column}")->and($key['foreign_table'])->toBe($table);
    }

    expect(Schema::hasColumn('students', 'deleted_at'))->toBeTrue();
});

it('AC-40: adds and drops the unique index without losing students or the foreign key', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Hanya relevan untuk SQLite.');
    }

    $found = glob(database_path('migrations/*_add_applicant_unique_index_to_students_table.php'));
    expect($found)->toHaveCount(1, 'Migration *_add_applicant_unique_index_to_students_table.php belum ada');

    $migration = (fn () => $this->resolvePath($found[0]))->call(app('migrator'));
    $applicant = Applicant::factory()->create();
    $student = Student::factory()->create(['applicant_id' => $applicant->id]);

    $migration->down();

    expect(collect(Schema::getIndexes('students'))->where('unique', true)->pluck('columns')->all())->not->toContain(['applicant_id']);

    $migration->up();

    expect(collect(Schema::getIndexes('students'))->where('unique', true)->pluck('columns')->all())->toContain(['applicant_id'])
        ->and(DB::table('students')->where('id', $student->id)->value('applicant_id'))->toBe($applicant->id)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and(collect(Schema::getForeignKeys('students'))->contains(fn (array $key): bool => $key['columns'] === ['applicant_id']))->toBeTrue();
});

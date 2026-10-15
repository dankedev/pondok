<?php

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a duplicate nis', function () {
    Student::factory()->create(['nis' => '2026001']);

    Student::factory()->create(['nis' => '2026001']);
})->throws(UniqueConstraintViolationException::class);

it('allows several students without nisn', function () {
    Student::factory()->count(2)->create(['nisn' => null]);

    expect(Student::query()->whereNull('nisn')->count())->toBe(2);
});

it('rejects a second enrollment for the same student in one academic year', function () {
    $enrollment = Enrollment::factory()->create();
    $otherClassroom = Classroom::factory()->create(['academic_year_id' => $enrollment->academic_year_id]);

    Enrollment::query()->create([
        'student_id' => $enrollment->student_id,
        'classroom_id' => $otherClassroom->id,
        'academic_year_id' => $enrollment->academic_year_id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('rejects two cohorts with the same entry year in one unit', function () {
    $unit = Unit::factory()->create();
    Cohort::factory()->create(['unit_id' => $unit->id, 'entry_year' => 2026]);

    Cohort::factory()->create(['unit_id' => $unit->id, 'entry_year' => 2026]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same entry year in different units', function () {
    Cohort::factory()->count(2)->create(['entry_year' => 2026]);

    expect(Cohort::query()->where('entry_year', 2026)->count())->toBe(2);
});

it('rejects two classrooms with the same name in one unit and academic year', function () {
    $classroom = Classroom::factory()->create(['name' => '1A']);

    Classroom::factory()->create([
        'academic_year_id' => $classroom->academic_year_id,
        'unit_id' => $classroom->unit_id,
        'name' => '1A',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same classroom name in another academic year or unit', function () {
    $classroom = Classroom::factory()->create(['name' => '1A']);

    Classroom::factory()->create(['unit_id' => $classroom->unit_id, 'academic_year_id' => AcademicYear::factory(), 'name' => '1A']);
    Classroom::factory()->create(['academic_year_id' => $classroom->academic_year_id, 'unit_id' => Unit::factory(), 'name' => '1A']);

    expect(Classroom::query()->where('name', '1A')->count())->toBe(3);
});

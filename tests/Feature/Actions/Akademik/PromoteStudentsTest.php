<?php

use App\Actions\Akademik\PromoteStudents;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->cohort = Cohort::factory()->create();
    $this->from = Classroom::factory()->create([
        'unit_id' => $this->cohort->unit_id,
        'academic_year_id' => AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        'grade' => 1,
        'name' => '1A',
    ]);
    $this->to = Classroom::factory()->create([
        'unit_id' => $this->cohort->unit_id,
        'academic_year_id' => AcademicYear::factory()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
        'grade' => 2,
        'name' => '2A',
    ]);
});

function enrolledStudent(Classroom $classroom, Cohort $cohort, array $attributes = []): Student
{
    $student = Student::factory()->forCohort($cohort)->create(['gender' => 'L', ...$attributes]);
    Enrollment::factory()->create(['student_id' => $student->id, 'classroom_id' => $classroom->id]);

    return $student;
}

it('promotes active students and keeps their previous class', function () {
    $students = collect([enrolledStudent($this->from, $this->cohort), enrolledStudent($this->from, $this->cohort)]);

    $result = app(PromoteStudents::class)->handle($this->from, $this->to);

    expect($result)->toBe(['promoted' => 2, 'skipped' => []]);
    $students->each(fn (Student $student) => expect($student->enrollments()->orderBy('id')->pluck('classroom_id')->all())
        ->toBe([$this->from->id, $this->to->id]));
});

it('does not promote students who are no longer active', function () {
    $graduate = enrolledStudent($this->from, $this->cohort, ['status' => 'keluar']);

    $result = app(PromoteStudents::class)->handle($this->from, $this->to);

    expect($result['promoted'])->toBe(0)
        ->and($graduate->enrollments()->count())->toBe(1);
});

it('does nothing for students already promoted when run again', function () {
    enrolledStudent($this->from, $this->cohort);
    app(PromoteStudents::class)->handle($this->from, $this->to);

    $result = app(PromoteStudents::class)->handle($this->from, $this->to);

    expect($result['promoted'])->toBe(0)
        ->and(Enrollment::query()->where('classroom_id', $this->to->id)->count())->toBe(1);
});

it('promotes only the selected students', function () {
    $selected = enrolledStudent($this->from, $this->cohort);
    $repeating = enrolledStudent($this->from, $this->cohort);

    app(PromoteStudents::class)->handle($this->from, $this->to, [$selected->id]);

    expect($selected->enrollments()->count())->toBe(2)
        ->and($repeating->enrollments()->count())->toBe(1);
});

it('skips students the target classroom cannot take and reports them', function () {
    $this->to->update(['capacity' => 1]);
    enrolledStudent($this->from, $this->cohort, ['name' => 'Ahmad']);
    enrolledStudent($this->from, $this->cohort, ['name' => 'Bilal']);

    $result = app(PromoteStudents::class)->handle($this->from, $this->to);

    expect($result['promoted'])->toBe(1)
        ->and($result['skipped'])->toBe([__('pondok.akademik.errors.classroom_full', ['name' => 'Bilal'])]);
});

it('rejects a target classroom in another unit', function () {
    $this->to->update(['unit_id' => Cohort::factory()->create()->unit_id]);

    expect(fn () => app(PromoteStudents::class)->handle($this->from, $this->to))
        ->toThrow(ValidationException::class, __('pondok.akademik.errors.promotion_unit_mismatch'));
});

it('rejects a target classroom that is not in a later academic year', function () {
    expect(fn () => app(PromoteStudents::class)->handle($this->to, $this->from))
        ->toThrow(ValidationException::class, __('pondok.akademik.errors.promotion_year_not_later'));
});

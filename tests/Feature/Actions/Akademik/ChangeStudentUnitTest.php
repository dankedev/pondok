<?php

use App\Actions\Akademik\ChangeStudentUnit;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

it('moves the student to the new unit and cohort and keeps the class history', function () {
    $enrollment = Enrollment::factory()->create();
    $student = $enrollment->student;
    $sltpCohort = Cohort::factory()->create();

    app(ChangeStudentUnit::class)->handle($student, $sltpCohort->unit, $sltpCohort);

    expect($student->fresh())
        ->unit_id->toBe($sltpCohort->unit_id)
        ->cohort_id->toBe($sltpCohort->id)
        ->and($student->enrollments()->pluck('id')->all())->toBe([$enrollment->id]);
});

it('rejects a cohort that does not belong to the target unit', function () {
    $student = Student::factory()->create();
    $originalUnitId = $student->unit_id;
    $otherUnitCohort = Cohort::factory()->create();

    expect(fn () => app(ChangeStudentUnit::class)->handle($student, Unit::factory()->create(), $otherUnitCohort))
        ->toThrow(ValidationException::class, __('pondok.akademik.errors.cohort_unit_mismatch'));

    expect($student->fresh()->unit_id)->toBe($originalUnitId);
});

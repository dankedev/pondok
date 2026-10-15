<?php

use App\Actions\Akademik\CreateStudent;
use App\Models\Cohort;
use App\Models\Student;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

it('creates a student in the unit of the cohort', function () {
    $cohort = Cohort::factory()->create();
    $attributes = Student::factory()->raw(['unit_id' => $cohort->unit_id, 'cohort_id' => $cohort->id]);

    $student = app(CreateStudent::class)->handle($attributes);

    expect($student->unit_id)->toBe($cohort->unit_id)
        ->and($student->cohort_id)->toBe($cohort->id);
});

it('rejects a cohort from another unit', function () {
    $cohort = Cohort::factory()->create();
    $attributes = Student::factory()->raw(['unit_id' => Unit::factory()->create()->id, 'cohort_id' => $cohort->id]);

    expect(fn () => app(CreateStudent::class)->handle($attributes))
        ->toThrow(ValidationException::class, __('pondok.akademik.errors.cohort_unit_mismatch'));

    expect(Student::query()->count())->toBe(0);
});

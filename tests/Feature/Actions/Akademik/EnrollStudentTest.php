<?php

use App\Actions\Akademik\EnrollStudent;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

function classroomFor(Student $student, array $attributes = []): Classroom
{
    return Classroom::factory()->create(['unit_id' => $student->unit_id, ...$attributes]);
}

it('enrolls the student in the classroom for its academic year', function () {
    $student = Student::factory()->create();
    $classroom = classroomFor($student);

    $enrollment = app(EnrollStudent::class)->handle($student, $classroom);

    expect($enrollment)
        ->classroom_id->toBe($classroom->id)
        ->academic_year_id->toBe($classroom->academic_year_id);
});

it('moves the student to a parallel classroom within the same academic year', function () {
    $student = Student::factory()->create();
    $classroomA = classroomFor($student, ['name' => '1A']);
    $classroomB = classroomFor($student, ['name' => '1B', 'academic_year_id' => $classroomA->academic_year_id]);

    app(EnrollStudent::class)->handle($student, $classroomA);
    app(EnrollStudent::class)->handle($student, $classroomB);

    expect($student->enrollments()->pluck('classroom_id')->all())->toBe([$classroomB->id]);
});

it('returns the existing enrollment when enrolled twice in the same classroom', function () {
    $student = Student::factory()->create();
    $classroom = classroomFor($student, ['capacity' => 1]);

    $first = app(EnrollStudent::class)->handle($student, $classroom);
    $second = app(EnrollStudent::class)->handle($student, $classroom);

    expect($second->id)->toBe($first->id)
        ->and(Enrollment::query()->count())->toBe(1);
});

it('rejects the enrollment', function (Closure $makeClassroom, array $studentAttributes, string $reason) {
    $student = Student::factory()->create(['gender' => 'L', ...$studentAttributes]);
    $classroom = $makeClassroom($student);

    expect(fn () => app(EnrollStudent::class)->handle($student, $classroom))
        ->toThrow(ValidationException::class, __("pondok.akademik.errors.{$reason}", ['name' => $student->name]));

    expect($student->enrollments()->where('classroom_id', $classroom->id)->exists())->toBeFalse();
})->with([
    'from another unit' => [fn (Student $student) => Classroom::factory()->create(), [], 'classroom_unit_mismatch'],
    'for the other gender' => [fn (Student $student) => classroomFor($student, ['gender' => 'P']), [], 'classroom_gender_mismatch'],
    'of an inactive student' => [fn (Student $student) => classroomFor($student), ['status' => 'lulus'], 'student_inactive'],
    'into a full classroom' => [function (Student $student) {
        $classroom = classroomFor($student, ['capacity' => 1]);
        Enrollment::factory()->create(['classroom_id' => $classroom->id]);

        return $classroom;
    }, [], 'classroom_full'],
]);

it('accepts any gender into a mixed classroom', function () {
    $student = Student::factory()->create(['gender' => 'P']);

    $enrollment = app(EnrollStudent::class)->handle($student, classroomFor($student, ['gender' => Classroom::GENDER_MIXED]));

    expect($enrollment->exists)->toBeTrue();
});

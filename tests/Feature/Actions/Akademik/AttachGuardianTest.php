<?php

use App\Actions\Akademik\AttachGuardian;
use App\Actions\Akademik\SetBillingContact;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

function billingContactIds(Student $student): array
{
    return $student->guardians()->wherePivot('is_billing_contact', true)->pluck('guardians.id')->all();
}

it('makes the first guardian the billing contact', function () {
    $student = Student::factory()->create();
    $father = Guardian::factory()->create();

    app(AttachGuardian::class)->handle($student, $father, 'ayah');

    expect(billingContactIds($student))->toBe([$father->id]);
});

it('keeps the existing billing contact when another guardian is attached', function () {
    $student = Student::factory()->create();
    $father = Guardian::factory()->create();
    app(AttachGuardian::class)->handle($student, $father, 'ayah');

    app(AttachGuardian::class)->handle($student, Guardian::factory()->create(), 'ibu');

    expect(billingContactIds($student))->toBe([$father->id]);
});

it('moves the billing contact to a newly attached guardian when requested', function () {
    $student = Student::factory()->create();
    app(AttachGuardian::class)->handle($student, Guardian::factory()->create(), 'ayah');
    $mother = Guardian::factory()->create();

    app(AttachGuardian::class)->handle($student, $mother, 'ibu', isBillingContact: true);

    expect(billingContactIds($student))->toBe([$mother->id]);
});

it('shares one guardian between siblings with a billing contact for each', function () {
    $guardian = Guardian::factory()->create();
    [$older, $younger] = Student::factory()->count(2)->create()->all();

    app(AttachGuardian::class)->handle($older, $guardian, 'ayah');
    app(AttachGuardian::class)->handle($younger, $guardian, 'ayah');

    expect($guardian->students()->count())->toBe(2)
        ->and(billingContactIds($older))->toBe([$guardian->id])
        ->and(billingContactIds($younger))->toBe([$guardian->id]);
});

it('switches the billing contact between attached guardians', function () {
    $student = Student::factory()->create();
    app(AttachGuardian::class)->handle($student, Guardian::factory()->create(), 'ayah');
    $mother = Guardian::factory()->create();
    app(AttachGuardian::class)->handle($student, $mother, 'ibu');

    app(SetBillingContact::class)->handle($student, $mother);

    expect(billingContactIds($student))->toBe([$mother->id]);
});

it('rejects a billing contact who is not a guardian of the student', function () {
    expect(fn () => app(SetBillingContact::class)->handle(Student::factory()->create(), Guardian::factory()->create()))
        ->toThrow(ValidationException::class, __('pondok.akademik.errors.guardian_not_attached'));
});

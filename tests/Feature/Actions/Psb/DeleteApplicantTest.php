<?php

use App\Actions\Psb\DeleteApplicant;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->f = pendaftaranFixture();
    $this->user = User::factory()->create();
    $this->applicant = pendaftaranApplicant();
});

it('AC-36: soft deletes an applicant and cancels active invoices with a reason', function () {
    app(GenerateRegistrationInvoices::class)->handle($this->applicant);

    app(DeleteApplicant::class)->handle($this->applicant, $this->user);

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeFalse()
        ->and(Applicant::withoutUnitScope()->withTrashed()->whereKey($this->applicant->id)->exists())->toBeTrue()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->pluck('status')->all())->toBe(['batal']);
});

it('AC-36: keeps the documents and the number of a deleted applicant', function () {
    pendaftaranDocument($this->applicant, $this->f->kk);

    app(DeleteApplicant::class)->handle($this->applicant, $this->user);

    expect(ApplicantDocument::query()->count())->toBe(1)
        ->and(Applicant::withoutUnitScope()->withTrashed()->sole()->registration_no)->toBe($this->applicant->registration_no);
});

it('AC-36: refuses when an invoice has a payment and changes nothing', function () {
    pendaftaranPayRegistration($this->applicant);

    pendaftaranAssertRejected(fn () => app(DeleteApplicant::class)->handle($this->applicant, $this->user), 'applicant_has_payments');

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeTrue()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->where('status', 'batal')->count())->toBe(0);
});

it('AC-36: refuses when a partial payment exists', function () {
    app(GenerateRegistrationInvoices::class)->handle($this->applicant);
    bayarRecord(Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->sole(), 50000);

    pendaftaranAssertRejected(fn () => app(DeleteApplicant::class)->handle($this->applicant, $this->user), 'applicant_has_payments');

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeTrue();
});

it('AC-36: refuses an applicant that already became a student', function () {
    Student::factory()->create(['applicant_id' => $this->applicant->id]);

    pendaftaranAssertRejected(fn () => app(DeleteApplicant::class)->handle($this->applicant, $this->user), 'applicant_converted');

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeTrue();
});

it('AC-36: frees the quota of a deleted applicant', function () {
    $this->f->wave->update(['quota' => 1]);

    expect($this->f->wave->fresh()->isFull())->toBeTrue();

    app(DeleteApplicant::class)->handle($this->applicant, $this->user);

    expect($this->f->wave->fresh()->isFull())->toBeFalse();
});

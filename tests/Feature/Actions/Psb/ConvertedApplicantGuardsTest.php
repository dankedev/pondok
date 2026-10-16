<?php

use App\Actions\Keuangan\SaveFeeOverride;
use App\Actions\Psb\ApplyTrackScholarship;
use App\Actions\Psb\DeleteApplicant;
use App\Actions\Psb\GenerateAcceptanceInvoices;
use App\Actions\Psb\RejectApplicant;
use App\Actions\Psb\ResolveApplicantGuardian;
use App\Models\Applicant;
use App\Models\Cohort;
use App\Models\FeeOverride;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

/**
 * Data masukan `SaveFeeOverride` (create) untuk pendaftar: SPP persen 10 mulai hari ini.
 *
 * @return array<string, mixed>
 */
function daftarUlangOverrideData(object $f, Applicant $applicant, array $overrides = []): array
{
    return [
        'applicant_id' => $applicant->id,
        'fee_type_id' => $f->spp->id,
        'mode' => 'persen',
        'value' => 10,
        'reason' => 'Keringanan tambahan',
        'valid_from' => '2026-10-15',
        'valid_until' => null,
        ...$overrides,
    ];
}

it('AC-30: blocks billing changes on a converted applicant', function () {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRun($applicant);
    $type = psbFeeType('saat_diterima', ['code' => 'KITAB2', 'name' => 'Kitab tambahan', 'billing_group' => 'PSB_KITAB2', 'priority' => 60]);
    keuanganRate($type, $this->f->y2027, $this->f->sltp, 300000);
    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['admission_track_id' => $this->f->tahfidzTrack->id]);
    $fresh = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => app(GenerateAcceptanceInvoices::class)->handle($fresh, $this->f->user), 'applicant_converted', 'applicant');
    daftarUlangAssertRejected(fn () => app(ApplyTrackScholarship::class)->handle($fresh, $this->f->user), 'applicant_converted', 'applicant');
    daftarUlangAssertRejected(fn () => app(SaveFeeOverride::class)->handle(daftarUlangOverrideData($this->f, $fresh), $this->f->user), 'applicant_converted', 'applicant_id', 'keuangan');

    expect(daftarUlangSnapshot())->toBe($before)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->whereNull('student_id')->count())->toBe(0)
        ->and(FeeOverride::query()->where('applicant_id', $applicant->id)->whereNull('student_id')->count())->toBe(0);
});

it('AC-30: blocks rejecting or deleting a converted applicant, also after the student is deleted', function (bool $trashed) {
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];

    if ($trashed) {
        $student->delete();
    }

    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => app(RejectApplicant::class)->handle(Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $this->f->user, 'Salah daftar'), 'applicant_converted', 'applicant');

    expect(fn () => app(DeleteApplicant::class)->handle(Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $this->f->user))->toThrow(ValidationException::class);

    expect(daftarUlangSnapshot())->toBe($before)
        ->and(Applicant::withoutGlobalScopes()->findOrFail($applicant->id))->status->toBe('diterima')->deleted_at->toBeNull();
})->with(['active student' => [false], 'deleted student' => [true]]);

it('AC-30: reports a converted applicant when deleting one that has no payments', function (bool $trashed) {
    Setting::set('modules.keuangan.enabled', false);
    $applicant = pendaftaranApplicant(['status' => 'diterima']);
    $student = daftarUlangRun($applicant)['student'];

    if ($trashed) {
        $student->delete();
    }

    daftarUlangAssertRejected(fn () => app(DeleteApplicant::class)->handle(Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $this->f->user), 'applicant_converted', 'applicant');

    expect(Applicant::withoutGlobalScopes()->findOrFail($applicant->id)->deleted_at)->toBeNull();
})->with(['active student' => [false], 'deleted student' => [true]]);

it('AC-30: still lets an existing override of the converted applicant be edited', function () {
    $applicant = daftarUlangApplicant($this->f);
    $override = FeeOverride::factory()->forApplicant($applicant->id)->percent(50)->between('2026-10-15', null)->create(['fee_type_id' => $this->f->spp->id]);
    $student = daftarUlangRun($applicant)['student'];

    $updated = app(SaveFeeOverride::class)->handle(daftarUlangOverrideData($this->f, $applicant, ['mode' => 'persen', 'value' => 30, 'reason' => 'Disesuaikan']), $this->f->user, $override->fresh());

    expect($updated->id)->toBe($override->id)
        ->and($updated->fresh()->value)->toBe(30)
        ->and($updated->fresh()->reason)->toBe('Disesuaikan')
        ->and($updated->fresh()->student_id)->toBe($student->id)
        ->and($updated->fresh()->applicant_id)->toBe($applicant->id);
});

it('AC-39: never leaves applicant overrides behind', function () {
    $applicant = daftarUlangApplicant($this->f);
    $stale = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
    $created = app(SaveFeeOverride::class)->handle(daftarUlangOverrideData($this->f, $stale), $this->f->user);

    $result = daftarUlangRun($stale);

    expect($created->fresh()->student_id)->toBe($result['student']->id)
        ->and($result['fee_overrides'])->toBe(1)
        ->and(daftarUlangActivities('keuangan', 'applicant_billing_transferred')->sole()->properties['fee_override_ids'])->toBe([$created->id]);

    daftarUlangAssertRejected(
        fn () => app(SaveFeeOverride::class)->handle(daftarUlangOverrideData($this->f, $stale, ['fee_type_id' => $this->f->uangPangkal->id]), $this->f->user),
        'applicant_converted',
        'applicant_id',
        'keuangan',
    );

    expect(FeeOverride::query()->where('applicant_id', $applicant->id)->whereNull('student_id')->count())->toBe(0)
        ->and(FeeOverride::query()->where('applicant_id', $applicant->id)->count())->toBe(1);
});

it('AC-39: tells the user which student the applicant became', function () {
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];

    try {
        app(SaveFeeOverride::class)->handle(daftarUlangOverrideData($this->f, $applicant), $this->f->user);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors()['applicant_id'])->implode(' '))->toContain($student->nis);
    }
});

it('AC-39: rolls back when an invoice of the applicant changes owner before the transfer', function () {
    $applicant = daftarUlangApplicant($this->f);
    $other = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2025]))->create();
    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->orderBy('id')->firstOrFail();
    $before = daftarUlangSnapshot();

    daftarUlangBind(ResolveApplicantGuardian::class, new class(app(ResolveApplicantGuardian::class), $invoice->id, $other->id) extends ResolveApplicantGuardian
    {
        public function __construct(private ResolveApplicantGuardian $real, private int $invoiceId, private int $studentId) {}

        public function handle(Applicant $applicant): array
        {
            $result = $this->real->handle($applicant);

            Invoice::withoutUnitScope()->whereKey($this->invoiceId)->update(['student_id' => $this->studentId]);

            return $result;
        }
    });

    expect(fn () => daftarUlangRun($applicant))->toThrow(RuntimeException::class, __('pondok.psb.errors.billing_transfer_mismatch'));

    expect(daftarUlangSnapshot())->toBe($before)
        ->and(Student::withoutGlobalScopes()->where('applicant_id', $applicant->id)->exists())->toBeFalse()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->whereNotNull('student_id')->count())->toBe(0);
});

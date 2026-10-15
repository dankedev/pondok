<?php

use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = seleksiFixture();
    $this->admin = psbUser('super-admin');
});

it('AC-32: reopens a rejected applicant without restoring invoices', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Dokumen palsu');
    $invoice = seleksiInvoices($applicant, true)->sole();
    $invoices = Invoice::withoutUnitScope()->count();
    $overrides = FeeOverride::query()->count();
    $before = seleksiActivities($applicant)->count();

    $result = seleksiRun('ReopenRejectedApplicant', $applicant->fresh(), $this->admin, 'Salah tolak');

    $fresh = Applicant::withoutGlobalScopes()->find($applicant->id);
    $activities = seleksiActivities($applicant);

    expect($result)->toBeInstanceOf(Applicant::class)
        ->and($fresh)->status->toBe('diajukan')->reviewed_by->toBe($this->admin->id)->review_note->toBe('Salah tolak')
        ->and($activities)->toHaveCount($before + 1)
        ->and($activities->last())->event->toBe('updated')->causer_id->toBe($this->admin->id)
        ->and($activities->last()->properties['old']['status'])->toBe('ditolak')
        ->and($activities->last()->properties['attributes']['status'])->toBe('diajukan')
        ->and($activities->last()->properties['note'])->toBe('Salah tolak')
        ->and($invoice->fresh()->status)->toBe('batal')
        ->and(Invoice::withoutUnitScope()->count())->toBe($invoices)
        ->and(FeeOverride::query()->count())->toBe($overrides);

    seleksiView($fresh, $this->f->user)->assertActionVisible('generateInvoices');
});

it('AC-32: needs a new registration invoice, paid in full, before the applicant can be verified again', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    seleksiAddDocuments($applicant);
    seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Dokumen palsu');
    seleksiRun('ReopenRejectedApplicant', $applicant->fresh(), $this->admin, 'Salah tolak');
    $reopened = Applicant::withoutGlobalScopes()->find($applicant->id);

    expect($reopened->registrationFeeSettled())->toBeTrue()
        ->and($reopened->acceptsUploads())->toBeTrue();

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $reopened, $this->f->user, null), 'registration_fee_unpaid');

    app(GenerateRegistrationInvoices::class)->handle($reopened, $this->f->user);
    $new = seleksiInvoices($applicant)->sole();

    expect($new->invoice_no)->toBe('INV-202611-00001')->and($new->status)->toBe('belum');

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $reopened->fresh(), $this->f->user, null), 'registration_fee_unpaid');

    bayarRecord($new, $new->remainingAmount());

    seleksiRun('MarkApplicantVerified', $reopened->fresh(), $this->f->user, null);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diverifikasi');
});

it('AC-32: requires a note and keeps the status when it is missing', function (string $note) {
    $applicant = seleksiApplicant('ditolak');
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('ReopenRejectedApplicant', $applicant, $this->admin, $note), 'review_note_required', 'review_note');

    expect(seleksiSnapshot($applicant))->toBe($before)
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak');
})->with(['empty' => [''], 'spaces' => ['   ']]);

it('AC-32: bills again with new invoices and a new scholarship after a cancelled acceptance is restored', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mengundurkan diri');
    seleksiRun('ReopenRejectedApplicant', $applicant->fresh(), $this->admin, 'Salah tolak');
    seleksiRun('MarkApplicantVerified', $applicant->fresh(), $this->f->user, null);
    $oldInvoices = seleksiInvoices($applicant, true)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB'])->pluck('id')->all();

    $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00', 'Asia/Jakarta'));

    seleksiAccept($applicant->fresh(), $this->f->user);

    $all = seleksiInvoices($applicant, true)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']);
    $active = $all->where('status', '<>', 'batal');
    $overrides = seleksiOverrides($applicant);

    expect($all)->toHaveCount(4)
        ->and($all->whereIn('id', $oldInvoices)->pluck('status')->unique()->all())->toBe(['batal'])
        ->and($active)->toHaveCount(2)
        ->and($active->pluck('id')->intersect($oldInvoices))->toBeEmpty()
        ->and($active->first()->period->toDateString())->toBe('2026-12-01')
        ->and($overrides)->toHaveCount(4)
        ->and($overrides->where('valid_from', '>=', '2026-12-05')->count())->toBe(2)
        ->and($overrides->filter(fn (FeeOverride $override): bool => $override->valid_from->toDateString() === '2026-12-05' && $override->valid_until === null)->count())->toBe(2)
        ->and($overrides->filter(fn (FeeOverride $override): bool => $override->valid_from->toDateString() === '2026-11-20' && $override->valid_until?->toDateString() === '2026-11-20')->count())->toBe(2);
});

it('AC-32: reopens only from rejected', function (string $from) {
    $applicant = seleksiApplicant($from);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('ReopenRejectedApplicant', $applicant, $this->admin, 'Salah tolak'), 'invalid_transition', 'status');

    expect(seleksiSnapshot($applicant))->toBe($before);
})->with(['draft', 'diajukan', 'diverifikasi', 'diterima', 'cadangan']);

it('AC-32: does not run the decision when the applicant was deleted', function () {
    $applicant = seleksiApplicant('ditolak');
    $applicant->delete();

    try {
        seleksiRun('ReopenRejectedApplicant', $applicant, $this->admin, 'Salah tolak');
    } catch (ValidationException $exception) {
        expect($exception->errors())->not->toBeEmpty();
    }

    expect(Applicant::withoutGlobalScopes()->withTrashed()->find($applicant->id)->status)->toBe('ditolak');
});

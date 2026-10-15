<?php

use App\Actions\Psb\MarkApplicantVerified;
use App\Actions\Psb\UploadApplicantDocument;
use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = seleksiFixture();
});

/**
 * Peta Action keputusan => [status awal => status tujuan] yang sah (tabel transisi plan F011).
 *
 * @return array<string, array<string, string>>
 */
function seleksiValidTransitions(): array
{
    return [
        'MarkApplicantVerified' => ['diajukan' => 'diverifikasi'],
        'ReturnApplicantToSubmitted' => ['diverifikasi' => 'diajukan'],
        'ReserveApplicant' => ['diverifikasi' => 'cadangan'],
        'AcceptApplicant' => ['diverifikasi' => 'diterima', 'cadangan' => 'diterima'],
        'RejectApplicant' => ['diajukan' => 'ditolak', 'diverifikasi' => 'ditolak', 'cadangan' => 'ditolak', 'diterima' => 'ditolak'],
        'ReopenRejectedApplicant' => ['ditolak' => 'diajukan'],
    ];
}

/**
 * @return array<string, array{string, string}> pasangan sah [Action, status awal]
 */
function seleksiValidPairs(): array
{
    $pairs = [];

    foreach (seleksiValidTransitions() as $action => $moves) {
        foreach (array_keys($moves) as $from) {
            $pairs["{$action} dari {$from}"] = [$action, $from];
        }
    }

    return $pairs;
}

/**
 * @return array<string, array{string, string}> pasangan tidak sah [Action, status awal]: 6 status x 6 Action dikurangi yang sah
 */
function seleksiInvalidPairs(): array
{
    $pairs = [];

    foreach (Applicant::STATUSES as $from) {
        foreach (seleksiValidTransitions() as $action => $moves) {
            if (! array_key_exists($from, $moves)) {
                $pairs["{$action} dari {$from}"] = [$action, $from];
            }
        }
    }

    return $pairs;
}

/**
 * Pendaftar `$status` yang langsung siap diproses semua Action (formulir lunas, berkas valid).
 */
function seleksiReady(string $status): Applicant
{
    return seleksiApplicant($status);
}

it('AC-5: knows every transition through canTransitionTo', function (string $from, string $to) {
    $applicant = new Applicant(['status' => $from]);
    $valid = collect(seleksiValidTransitions())->contains(fn (array $moves): bool => ($moves[$from] ?? null) === $to);

    expect($applicant->canTransitionTo($to))->toBe($valid);
})->with(function () {
    foreach (Applicant::STATUSES as $from) {
        foreach (Applicant::STATUSES as $to) {
            yield "{$from} ke {$to}" => [$from, $to];
        }
    }
});

it('AC-5: moves along every valid transition', function (string $action, string $from) {
    $applicant = seleksiReady($from);
    $before = seleksiActivities($applicant)->count();
    $by = $action === 'ReopenRejectedApplicant' ? psbUser('super-admin') : $this->f->user;

    seleksiRun($action, $applicant, $by);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe(seleksiValidTransitions()[$action][$from])
        ->and(seleksiActivities($applicant))->toHaveCount($before + 1);
})->with(fn () => seleksiValidPairs());

it('AC-5: refuses every other pair without touching anything', function (string $action, string $from) {
    $applicant = seleksiReady($from);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun($action, $applicant, $this->f->user), 'invalid_transition', 'status');

    expect(seleksiSnapshot($applicant))->toBe($before);
})->with(fn () => seleksiInvalidPairs());

it('AC-6: records the decision with one activity and no personal data', function () {
    $applicant = seleksiApplicant();
    $before = seleksiActivities($applicant)->count();

    $result = seleksiRun('ReserveApplicant', $applicant, $this->f->user, '  Nilai tes di bawah batas  ');

    $fresh = Applicant::withoutGlobalScopes()->find($applicant->id);
    $activities = seleksiActivities($applicant);

    expect($result)->toBeInstanceOf(Applicant::class)
        ->and($fresh)->status->toBe('cadangan')->reviewed_by->toBe($this->f->user->id)->review_note->toBe('Nilai tes di bawah batas')
        ->and($fresh->reviewed_at->equalTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Jakarta')))->toBeTrue()
        ->and($activities)->toHaveCount($before + 1)
        ->and($activities->last())->log_name->toBe('psb')->event->toBe('updated')->causer_id->toBe($this->f->user->id)
        ->and($activities->last()->description)->toBe(seleksiText('pondok.applicant.activity_decided'))
        ->and($activities->last()->properties['old']['status'])->toBe('diverifikasi')
        ->and($activities->last()->properties['attributes']['status'])->toBe('cadangan')
        ->and($activities->last()->properties['note'])->toBe('Nilai tes di bawah batas')
        ->and($activities->last()->properties->keys()->sort()->values()->all())->toBe(['attributes', 'note', 'old']);
});

it('AC-6: overwrites the review data with the last decision and clears an old note', function () {
    $applicant = seleksiApplicant();
    seleksiRun('ReserveApplicant', $applicant, $this->f->user, 'Nilai tes di bawah batas');

    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:05', 'Asia/Jakarta'));
    $other = psbUser('admin-psb');
    $before = seleksiActivities($applicant)->count();

    $result = seleksiAccept($applicant->fresh(), $other);

    $fresh = Applicant::withoutGlobalScopes()->find($applicant->id);

    expect($result['applicant'])->toBeInstanceOf(Applicant::class)
        ->and($fresh)->status->toBe('diterima')->reviewed_by->toBe($other->id)->review_note->toBeNull()
        ->and($fresh->reviewed_at->equalTo(CarbonImmutable::parse('2026-11-20 10:05', 'Asia/Jakarta')))->toBeTrue()
        ->and(seleksiActivities($applicant))->toHaveCount($before + 1)
        ->and(seleksiActivities($applicant)->last()->properties['note'])->toBeNull();
});

it('AC-7: requires a note for decisions that need one', function (string $action, string $from, string $note) {
    $applicant = seleksiReady($from);
    $before = seleksiSnapshot($applicant);
    $by = $action === 'ReopenRejectedApplicant' ? psbUser('super-admin') : $this->f->user;

    pendaftaranAssertRejected(fn () => seleksiRun($action, $applicant, $by, $note), 'review_note_required', 'review_note');

    expect(seleksiSnapshot($applicant))->toBe($before);
})->with(function () {
    foreach ([['ReserveApplicant', 'diverifikasi'], ['ReturnApplicantToSubmitted', 'diverifikasi'], ['RejectApplicant', 'diajukan'], ['RejectApplicant', 'diterima'], ['ReopenRejectedApplicant', 'ditolak']] as [$action, $from]) {
        foreach (['empty' => '', 'spaces' => '   ', 'whitespace' => "\n\t "] as $label => $note) {
            yield "{$action} dari {$from} dengan {$label}" => [$action, $from, $note];
        }
    }
});

it('AC-7: refuses a note longer than 1000 characters in every decision', function (string $action, string $from) {
    $applicant = seleksiReady($from);
    $before = seleksiSnapshot($applicant);
    $by = $action === 'ReopenRejectedApplicant' ? psbUser('super-admin') : $this->f->user;

    pendaftaranAssertRejected(fn () => seleksiRun($action, $applicant, $by, str_repeat('a', 1001)), 'review_note_invalid', 'review_note');

    expect(seleksiSnapshot($applicant))->toBe($before);
})->with(fn () => seleksiValidPairs());

it('AC-7: accepts a note of exactly 1000 characters in every decision', function (string $action, string $from) {
    $applicant = seleksiReady($from);
    $by = $action === 'ReopenRejectedApplicant' ? psbUser('super-admin') : $this->f->user;

    seleksiRun($action, $applicant, $by, str_repeat('a', 1000));

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->review_note)->toBe(str_repeat('a', 1000));
})->with(fn () => seleksiValidPairs());

it('AC-7: trims the note before counting its length', function () {
    $applicant = seleksiApplicant();

    seleksiRun('ReserveApplicant', $applicant, $this->f->user, '  '.str_repeat('a', 1000).'  ');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->review_note)->toBe(str_repeat('a', 1000));
});

it('AC-8: refuses verification while the registration fee is unpaid', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    seleksiAddDocuments($applicant);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null), 'registration_fee_unpaid');

    try {
        seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null);
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain(__('pondok.applicant.generate_invoices'));
    }

    expect(seleksiSnapshot($applicant))->toBe($before);
});

it('AC-8: refuses verification while a required document is not valid', function () {
    $applicant = seleksiApplicant('diajukan');
    ApplicantDocument::query()->where('applicant_id', $applicant->id)->where('admission_requirement_id', $this->f->akta->id)->update(['status' => 'menunggu']);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null), 'documents_incomplete');

    try {
        seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null);
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain('1');
    }

    expect(seleksiSnapshot($applicant))->toBe($before);
});

it('AC-8: counts a required document that was never uploaded as missing', function () {
    $applicant = seleksiApplicant('diajukan');
    ApplicantDocument::query()->where('applicant_id', $applicant->id)->where('admission_requirement_id', $this->f->kk->id)->delete();

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null), 'documents_incomplete');
});

it('AC-8: verifies when every required document is valid and the optional one is missing', function () {
    $applicant = seleksiApplicant('diajukan');

    expect(ApplicantDocument::query()->where('applicant_id', $applicant->id)->where('admission_requirement_id', $this->f->rapor->id)->exists())->toBeFalse();

    $result = seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null);

    expect($result->status)->toBe('diverifikasi')
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diverifikasi');
});

it('AC-8: checks only the documents when the finance module is off and nothing was billed', function () {
    Setting::set('modules.keuangan.enabled', false);
    $applicant = pendaftaranApplicant();
    seleksiAddDocuments($applicant);

    expect($applicant->registrationFeeRequired())->toBeFalse()
        ->and($applicant->registrationFeeCleared())->toBeTrue();

    seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null);

    expect($applicant->fresh()->status)->toBe('diverifikasi');
});

it('AC-8: checks only the documents when the unit has no registration rate and nothing was billed', function () {
    FeeRate::query()->where('fee_type_id', $this->f->formulir->id)->delete();
    $applicant = pendaftaranApplicant();
    seleksiAddDocuments($applicant);

    expect($applicant->registrationFeeRequired())->toBeFalse()
        ->and($applicant->registrationFeeCleared())->toBeTrue();

    seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null);

    expect($applicant->fresh()->status)->toBe('diverifikasi');
});

it('AC-8: requires the registration fee when it has a rate but was never billed', function () {
    $applicant = pendaftaranApplicant();
    seleksiAddDocuments($applicant);

    expect($applicant->registrationFeeRequired())->toBeTrue()
        ->and($applicant->registrationFeeCleared())->toBeFalse();

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $applicant, $this->f->user, null), 'registration_fee_unpaid');
});

it('AC-8: never counts a cancelled registration invoice as paid', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    seleksiAddDocuments($applicant);
    Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->update(['status' => 'batal']);

    expect($applicant->registrationFeeSettled())->toBeTrue()
        ->and($applicant->registrationFeeCleared())->toBeFalse();

    pendaftaranAssertRejected(fn () => seleksiRun('MarkApplicantVerified', $applicant->fresh(), $this->f->user, null), 'registration_fee_unpaid');
});

it('AC-8: lists the required documents that are not valid yet', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    seleksiAddDocuments($applicant, names: ['Kartu Keluarga']);

    $missing = $applicant->missingRequiredDocuments();

    expect($missing)->toBeInstanceOf(Collection::class)
        ->and($missing->first())->toBeInstanceOf(AdmissionRequirement::class)
        ->and($missing->pluck('name')->all())->toBe(['Akta kelahiran']);
});

it('AC-8: uses the container so the action can be resolved', function () {
    expect(app(MarkApplicantVerified::class))->toBeInstanceOf(MarkApplicantVerified::class);
});

it('AC-9: returns to submitted and opens uploads again', function () {
    $applicant = seleksiApplicant('diverifikasi');
    ApplicantDocument::query()->where('applicant_id', $applicant->id)->where('admission_requirement_id', $this->f->akta->id)
        ->update(['status' => 'ditolak', 'note' => 'Foto buram']);

    expect($applicant->acceptsUploads())->toBeFalse();

    seleksiRun('ReturnApplicantToSubmitted', $applicant, $this->f->user, 'Akta buram, unggah ulang');

    $fresh = Applicant::withoutGlobalScopes()->find($applicant->id);

    expect($fresh)->status->toBe('diajukan')->review_note->toBe('Akta buram, unggah ulang')
        ->and($fresh->acceptsUploads())->toBeTrue()
        ->and(ApplicantDocument::query()->where('applicant_id', $applicant->id)->where('status', 'valid')->count())->toBe(1);

    $document = app(UploadApplicantDocument::class)->handle($fresh, $this->f->akta, pendaftaranPdf('akta.pdf'));

    expect($document)->applicant_id->toBe($applicant->id)->admission_requirement_id->toBe($this->f->akta->id)->status->toBe('menunggu');
});

it('AC-10: refuses a decision on a deleted applicant', function (string $action, string $from) {
    $applicant = seleksiReady($from);
    $applicant->delete();
    $by = $action === 'ReopenRejectedApplicant' ? psbUser('super-admin') : $this->f->user;

    pendaftaranAssertRejected(fn () => seleksiRun($action, $applicant, $by), 'applicant_not_found');

    expect(Applicant::withoutGlobalScopes()->withTrashed()->find($applicant->id)->status)->toBe($from)
        ->and(seleksiInvoices($applicant)->where('billing_group', 'PSB_TERIMA'))->toBeEmpty();
})->with(fn () => seleksiValidPairs());

it('AC-10: reads the status again from the database for a stale instance', function () {
    $stale = seleksiApplicant('diverifikasi');
    Applicant::withoutGlobalScopes()->whereKey($stale->id)->update(['status' => 'ditolak']);
    $invoices = Invoice::withoutUnitScope()->count();
    $overrides = FeeOverride::query()->count();

    pendaftaranAssertRejected(fn () => seleksiAccept($stale, $this->f->user), 'invalid_transition', 'status');

    expect(Applicant::withoutGlobalScopes()->find($stale->id)->status)->toBe('ditolak')
        ->and(Invoice::withoutUnitScope()->count())->toBe($invoices)
        ->and(FeeOverride::query()->count())->toBe($overrides);
});

it('AC-10: keeps the decision off the instance when the transition is invalid', function () {
    $applicant = seleksiApplicant('diajukan');

    pendaftaranAssertRejected(fn () => seleksiRun('AcceptApplicant', $applicant, $this->f->user), 'invalid_transition', 'status');

    expect($applicant->status)->toBe('diajukan')
        ->and(Activity::query()->where('description', __('pondok.applicant.activity_decided'))->count())->toBe(0);
});

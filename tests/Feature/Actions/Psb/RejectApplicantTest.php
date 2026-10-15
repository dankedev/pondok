<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\CancelPayment;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = seleksiFixture();
});

/**
 * Tagihan aktif dan semua tagihan, per grup, dengan statusnya.
 *
 * @return array<string, string>
 */
function seleksiInvoiceStatuses(Applicant $applicant): array
{
    return seleksiInvoices($applicant, true)->mapWithKeys(fn (Invoice $invoice): array => ["{$invoice->billing_group}#{$invoice->id}" => $invoice->status])->all();
}

it('AC-20: rejects from submitted and cancels the unpaid registration invoice without finance permissions', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    $invoice = seleksiInvoices($applicant)->sole();

    expect($this->f->user->can('cancel:invoice'))->toBeFalse();

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Tidak memenuhi syarat');

    $activity = Activity::query()->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->where('log_name', 'keuangan')->orderByDesc('id')->first();

    expect(Applicant::withoutGlobalScopes()->find($applicant->id))->status->toBe('ditolak')->review_note->toBe('Tidak memenuhi syarat')
        ->and($result)->toHaveKeys(['applicant', 'cancelled_invoices', 'ended_overrides'])
        ->and($result['cancelled_invoices'])->toBe(1)
        ->and($result['ended_overrides'])->toBe(0)
        ->and($invoice->fresh()->status)->toBe('batal')
        ->and($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->f->user->id)
        ->and($activity->properties['reason'])->toBe(seleksiText('pondok.applicant.cancel_reason_rejected'))
        ->and($activity->properties['attributes']['status'])->toBe('batal');
});

it('AC-20: leaves a paid registration invoice alone', function () {
    $applicant = seleksiApplicant('diajukan');
    $invoice = seleksiInvoices($applicant)->sole();

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Tidak memenuhi syarat');

    expect($result['cancelled_invoices'])->toBe(0)
        ->and($invoice->fresh())->status->toBe('lunas')->paid_total->toBe(250000)
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak');
});

it('AC-20: leaves a partly paid registration invoice for the treasurer', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);
    $invoice = seleksiInvoices($applicant)->sole();
    bayarRecord($invoice, 100000);

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Tidak memenuhi syarat');

    expect($result['cancelled_invoices'])->toBe(0)
        ->and($invoice->fresh())->status->toBe('sebagian')->paid_total->toBe(100000);
});

it('AC-20: rejects from reserve', function () {
    $applicant = seleksiApplicant('cadangan');

    seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Kuota penuh');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id))->status->toBe('ditolak')->review_note->toBe('Kuota penuh');
});

it('AC-21: cancels an acceptance with its invoices and scholarship', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $custom = FeeOverride::factory()->forApplicant($applicant->id)->discount(50000)->between('2026-11-01', null)->create([
        'fee_type_id' => $this->f->kitab->id,
        'scholarship_id' => null,
    ]);
    $registration = seleksiInvoices($applicant, group: 'PSB_DAFTAR')->sole();

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Mengundurkan diri');

    $overrides = seleksiOverrides($applicant)->keyBy(fn (FeeOverride $override): string => $override->feeType->code);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and($result['cancelled_invoices'])->toBe(2)
        ->and($result['ended_overrides'])->toBe(2)
        ->and(seleksiInvoices($applicant, true)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB'])->pluck('status')->unique()->all())->toBe(['batal'])
        ->and($registration->fresh()->status)->toBe('lunas')
        ->and($overrides['UANG_PANGKAL']->valid_until->toDateString())->toBe('2026-11-20')
        ->and($overrides['SPP']->valid_until->toDateString())->toBe('2026-11-20')
        ->and($custom->fresh()->valid_until)->toBeNull();
});

it('AC-21: cancels again on the same day after a restore and a second acceptance', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    $admin = psbUser('super-admin');

    seleksiAccept($applicant, $this->f->user);
    seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Salah input');
    seleksiRun('ReopenRejectedApplicant', $applicant->fresh(), $admin, 'Salah tolak');
    seleksiRun('MarkApplicantVerified', $applicant->fresh(), $this->f->user, null);

    $second = seleksiAccept($applicant->fresh(), $this->f->user);

    expect(seleksiSkipped($second['billing']['scholarship']['skipped'], $this->f->uangPangkal->id, 'already_applied'))->toBeTrue()
        ->and(seleksiOverrides($applicant))->toHaveCount(2)
        ->and(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toHaveCount(2);

    $result = seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Salah lagi');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and($result['cancelled_invoices'])->toBe(2)
        ->and($result['ended_overrides'])->toBe(0)
        ->and(seleksiInvoices($applicant, true)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB'])->pluck('status')->unique()->all())->toBe(['batal'])
        ->and(seleksiOverrides($applicant))->toHaveCount(2);
});

it('AC-21: skips an override that the treasurer already shortened to today', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    seleksiOverrides($applicant)->firstWhere('fee_type_id', $this->f->uangPangkal->id)->update(['valid_until' => '2026-11-20']);

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Mengundurkan diri');

    expect($result['ended_overrides'])->toBe(1)
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and(seleksiOverrides($applicant)->pluck('valid_until')->map->toDateString()->all())->toBe(['2026-11-20', '2026-11-20']);
});

it('AC-21: does not touch an override that ends after today beyond ending the automatic ones', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    seleksiOverrides($applicant)->firstWhere('fee_type_id', $this->f->spp->id)->update(['valid_until' => '2027-06-30']);

    $result = seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Mengundurkan diri');

    expect($result['ended_overrides'])->toBe(2)
        ->and(seleksiOverrides($applicant)->every(fn (FeeOverride $override): bool => $override->valid_until->toDateString() === '2026-11-20'))->toBeTrue();
});

it('AC-22: refuses to cancel an acceptance that has a payment and changes nothing', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $terima = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();
    $payment = bayarRecord($terima, 100000);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mengundurkan diri'), 'acceptance_has_payments');

    expect(seleksiSnapshot($applicant))->toBe($before)
        ->and(seleksiOverrides($applicant)->pluck('valid_until')->filter()->all())->toBe([]);

    app(CancelPayment::class)->handle($payment, User::factory()->create(), 'Salah input');

    $result = seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mengundurkan diri');

    expect($result['cancelled_invoices'])->toBe(2)
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak');
});

it('AC-22: refuses to cancel an acceptance of an applicant that already became a student', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    Student::factory()->create(['applicant_id' => $applicant->id]);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mengundurkan diri'), 'applicant_converted');

    expect(seleksiSnapshot($applicant))->toBe($before);
});

it('AC-20: rolls everything back when cancelling an invoice fails halfway', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $before = seleksiSnapshot($applicant);

    $failing = new class extends CancelInvoice
    {
        public int $calls = 0;

        public function handle(Invoice $invoice, User $user, string $reason): Invoice
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('Gagal membatalkan tagihan kedua');
            }

            return parent::handle($invoice, $user, $reason);
        }
    };
    app()->instance(CancelInvoice::class, $failing);

    expect(fn () => seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mengundurkan diri'))->toThrow(RuntimeException::class)
        ->and($failing->calls)->toBe(2)
        ->and(seleksiSnapshot($applicant))->toBe($before);
});

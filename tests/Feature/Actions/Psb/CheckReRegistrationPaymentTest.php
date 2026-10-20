<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Psb\CheckReRegistrationPayment;
use App\Actions\Psb\GenerateAcceptanceInvoices;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

function daftarUlangCheck(Applicant $applicant): array
{
    return app(CheckReRegistrationPayment::class)->handle($applicant);
}

it('AC-2: requires the nominal minimum of the applicant unit and converts once it is met', function () {
    daftarUlangMinimum('SLTP', 3000000);
    $applicant = daftarUlangApplicant($this->f, paid: 2999999);

    expect(daftarUlangCheck($applicant))->toMatchArray([
        'applies' => true, 'minimum' => 3000000, 'required' => 3000000, 'total' => 8000000, 'paid' => 2999999,
        'meets' => false, 'reason' => 'min_payment_not_met',
    ]);

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met', 'payment');

    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 1);

    expect(daftarUlangCheck($applicant))->toMatchArray(['paid' => 3000000, 'meets' => true, 'reason' => null])
        ->and(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class);
});

it('AC-3: requires full payment when the unit minimum is null', function () {
    daftarUlangMinimum('SLTP', null);
    $applicant = daftarUlangApplicant($this->f, paid: 7999999);

    expect(daftarUlangCheck($applicant))->toMatchArray(['minimum' => null, 'required' => 8000000, 'meets' => false, 'reason' => 'min_payment_not_met']);

    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 1);

    expect(daftarUlangCheck($applicant))->toMatchArray(['required' => 8000000, 'paid' => 8000000, 'meets' => true]);
});

it('AC-4: asks for no payment when the unit minimum is zero', function () {
    daftarUlangMinimum('SLTP', 0);
    $applicant = daftarUlangApplicant($this->f, paid: 0);

    expect(daftarUlangCheck($applicant))->toMatchArray(['minimum' => 0, 'required' => 0, 'paid' => 0, 'meets' => true, 'reason' => null])
        ->and(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class);
});

it('AC-5: only asks for the invoice total when it is smaller than the minimum', function () {
    daftarUlangMinimum('SLTP', 10000000);
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    $invoice = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();
    Invoice::withoutUnitScope()->whereKey($invoice->id)->update(['total' => 2000000, 'paid_total' => 1999999]);

    expect(daftarUlangCheck($applicant))->toMatchArray(['minimum' => 10000000, 'required' => 2000000, 'total' => 2000000, 'meets' => false, 'reason' => 'min_payment_not_met']);

    Invoice::withoutUnitScope()->whereKey($invoice->id)->update(['paid_total' => 2000000]);

    expect(daftarUlangCheck($applicant))->toMatchArray(['required' => 2000000, 'meets' => true]);
});

it('AC-6: reads the minimum of the applicant unit, not of another unit', function () {
    daftarUlangMinimum('SLTP', 3000000);
    daftarUlangMinimum('SLTA', 0);
    $applicant = daftarUlangApplicant($this->f, paid: 0);

    expect(daftarUlangCheck($applicant))->toMatchArray(['minimum' => 3000000, 'required' => 3000000, 'meets' => false]);
});

it('AC-6: counts only active acceptance invoices', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 0);

    expect(daftarUlangCheck($applicant))->toMatchArray(['total' => 8000000, 'paid' => 0, 'paid_percent' => 0, 'meets' => false]);

    $original = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();
    app(CancelInvoice::class)->handle($original, $this->f->user, 'Salah tagih');
    app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user);
    $replacement = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();
    bayarRecord($replacement, 4000000);

    expect($replacement->id)->not->toBe($original->id)
        ->and(daftarUlangCheck($applicant))->toMatchArray(['total' => 8000000, 'paid' => 4000000, 'paid_percent' => 50, 'meets' => true]);
});

it('AC-6: treats a zero total acceptance invoice as fully paid', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    app(CancelInvoice::class)->handle(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), $this->f->user, 'Beasiswa penuh');

    foreach ([$this->f->uangPangkal, $this->f->seragam] as $type) {
        FeeOverride::factory()->forApplicant($applicant->id)->free()->create(['fee_type_id' => $type->id, 'valid_from' => '2026-10-01']);
    }

    app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user);
    $free = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();
    Invoice::query()->whereKey($free->id)->update(['status' => 'lunas']);

    expect($free->total)->toBe(0)
        ->and(daftarUlangCheck($applicant))->toMatchArray(['total' => 0, 'paid' => 0, 'paid_percent' => 100, 'meets' => true]);
});

it('AC-6: refuses while the acceptance invoices are not created yet', function () {
    $applicant = daftarUlangApplicant($this->f, invoices: false);

    expect(daftarUlangCheck($applicant))->toMatchArray(['applies' => true, 'meets' => false, 'reason' => 'acceptance_invoices_missing']);

    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'acceptance_invoices_missing', 'payment');

    expect(daftarUlangSnapshot())->toBe($before);
});

it('AC-6: has no requirement when no acceptance fee has a rate', function () {
    $applicant = daftarUlangApplicant($this->f, invoices: false);
    FeeRate::query()->whereIn('fee_type_id', [$this->f->uangPangkal->id, $this->f->seragam->id])->delete();

    expect(daftarUlangCheck($applicant))->toMatchArray(['meets' => true, 'reason' => null]);

    expect(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class);
});

it('AC-6: drops the payment requirement when the finance module is off but still moves the invoices', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    Setting::set('modules.keuangan.enabled', false);

    expect(daftarUlangCheck($applicant))->toMatchArray(['applies' => false, 'meets' => true]);

    $result = daftarUlangRun($applicant);

    expect($result['invoices'])->toBe(2)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->whereNull('student_id')->count())->toBe(0)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->where('student_id', $result['student']->id)->count())->toBe(2);
});

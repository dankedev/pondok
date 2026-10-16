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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

function daftarUlangCheck(Applicant $applicant): array
{
    return app(CheckReRegistrationPayment::class)->handle($applicant);
}

it('AC-5: requires the minimum paid percent', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 3999999);

    expect(daftarUlangCheck($applicant))->toMatchArray([
        'applies' => true, 'required_percent' => 50, 'total' => 8000000, 'paid' => 3999999, 'paid_percent' => 49,
        'meets' => false, 'reason' => 'min_payment_not_met',
    ]);

    $before = daftarUlangSnapshot();

    try {
        daftarUlangRun($applicant);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payment');
        $message = collect($exception->errors()['payment'])->implode(' ');
        expect($message)->toContain('49%')->toContain('50%');
    }

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met', 'payment');

    expect(daftarUlangSnapshot())->toBe($before)->and(Student::withoutGlobalScopes()->count())->toBe(0);

    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 1);

    expect(daftarUlangCheck($applicant))->toMatchArray(['paid' => 4000000, 'paid_percent' => 50, 'meets' => true, 'reason' => null]);

    $result = daftarUlangRun($applicant);

    expect($result['student'])->toBeInstanceOf(Student::class)->and(Student::withoutGlobalScopes()->count())->toBe(1);
});

it('AC-6: reads the configured percent', function (mixed $setting, int $paid, bool $meets) {
    Setting::set('psb.min_paid_percent', $setting);
    $applicant = daftarUlangApplicant($this->f, paid: $paid);

    expect(daftarUlangCheck($applicant))->toMatchArray(['required_percent' => $setting, 'meets' => $meets]);

    if ($meets) {
        expect(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class);
    } else {
        daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met', 'payment');
    }
})->with([
    '100 percent, almost paid' => [100, 7999999, false],
    '100 percent, fully paid' => [100, 8000000, true],
    '0 percent, nothing paid' => [0, 0, true],
]);

it('AC-6: falls back to 50 percent for an invalid setting and warns', function (mixed $setting) {
    Setting::set('psb.min_paid_percent', $setting);
    $applicant = daftarUlangApplicant($this->f, paid: 4000000);
    Log::spy();

    expect(daftarUlangCheck($applicant))->toMatchArray(['required_percent' => 50, 'meets' => true]);

    Log::shouldHaveReceived('warning')->atLeast()->once();
})->with([
    'text' => ['abc'],
    'above 100' => [150],
    'negative' => [-1],
    'decimal text' => ['50.5'],
]);

it('AC-6: accepts a numeric string made of digits', function () {
    Setting::set('psb.min_paid_percent', '75');
    $applicant = daftarUlangApplicant($this->f, paid: 4000000);

    expect(daftarUlangCheck($applicant))->toMatchArray(['required_percent' => 75, 'paid_percent' => 50, 'meets' => false]);
});

it('AC-7: counts only active acceptance invoices', function () {
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

it('AC-7: treats a zero total acceptance invoice as fully paid', function () {
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

it('AC-8: refuses while the acceptance invoices are not created yet', function () {
    $applicant = daftarUlangApplicant($this->f, invoices: false);

    expect(daftarUlangCheck($applicant))->toMatchArray(['applies' => true, 'meets' => false, 'reason' => 'acceptance_invoices_missing']);

    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'acceptance_invoices_missing', 'payment');

    expect(daftarUlangSnapshot())->toBe($before);
});

it('AC-8: has no requirement when no acceptance fee has a rate', function () {
    $applicant = daftarUlangApplicant($this->f, invoices: false);
    FeeRate::query()->whereIn('fee_type_id', [$this->f->uangPangkal->id, $this->f->seragam->id])->delete();

    expect(daftarUlangCheck($applicant))->toMatchArray(['meets' => true, 'reason' => null]);

    expect(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class);
});

it('AC-8: drops the payment requirement when the finance module is off but still moves the invoices', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    Setting::set('modules.keuangan.enabled', false);

    expect(daftarUlangCheck($applicant))->toMatchArray(['applies' => false, 'meets' => true]);

    $result = daftarUlangRun($applicant);

    expect($result['invoices'])->toBe(2)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->whereNull('student_id')->count())->toBe(0)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->where('student_id', $result['student']->id)->count())->toBe(2);
});

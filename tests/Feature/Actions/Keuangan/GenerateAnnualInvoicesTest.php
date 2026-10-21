<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = tagihanFixture();
});

it('AC-1: bills an annual fee once per student in the configured month of the academic year', function () {
    $f = $this->f;
    $type = tahunanFeeType($f);
    FeeType::factory()->create(['code' => 'LAIN_MANUAL', 'name' => 'Manual', 'frequency' => 'manual', 'billing_group' => 'DAFTAR_ULANG']);
    $student = tagihanStudent($f);

    $result = tahunanGenerate($f->year);

    $invoice = tahunanInvoiceFor($student);
    $item = tagihanItems($invoice)->sole();

    expect($result)->toMatchArray(['period' => '2026-07-01', 'created_invoices' => 1, 'added_items' => 0, 'existing_invoices' => 0, 'skipped' => []])
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and($invoice->academic_year_id)->toBe($f->year->id)
        ->and($invoice->due_on->toDateString())->toBe('2026-07-10')
        ->and($invoice->total)->toBe(750000)
        ->and($item->fee_type_id)->toBe($type->id)
        ->and($item->base_amount)->toBe(750000)
        ->and($item->discount_amount)->toBe(0)
        ->and($item->amount)->toBe(750000)
        ->and($item->description)->toBe('Daftar ulang 2026/2027');
});

it('AC-2: is idempotent', function () {
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);

    tahunanGenerate($f->year);
    $second = tahunanGenerate($f->year);

    expect($second['created_invoices'])->toBe(0)
        ->and($second['added_items'])->toBe(0)
        ->and($second['existing_invoices'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1);
});

it('AC-3: does not bill again after the billing month is changed', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $student = tagihanStudent($f);
    tahunanGenerate($f->year);

    Setting::set('keuangan.annual_billing_month', 9);
    $result = tahunanGenerate($f->year);

    expect($result['created_invoices'])->toBe(0)
        ->and($result['added_items'])->toBe(0)
        ->and($result['existing_invoices'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1)
        ->and(tahunanInvoiceFor($student, period: '2026-09-01'))->toBeNull();
});

it('AC-3: bills in the new month when the earlier annual invoice was cancelled', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $student = tagihanStudent($f);
    tahunanGenerate($f->year);
    $first = tahunanInvoiceFor($student);
    app(CancelInvoice::class)->handle($first, User::factory()->create(), 'Salah tagih');

    Setting::set('keuangan.annual_billing_month', 9);
    $result = tahunanGenerate($f->year);

    $second = tahunanInvoiceFor($student, period: '2026-09-01');

    expect($result['created_invoices'])->toBe(1)
        ->and($result['period'])->toBe('2026-09-01')
        ->and($second)->not->toBeNull()
        ->and($second->invoice_no)->not->toBe($first->invoice_no)
        ->and($second->total)->toBe(750000);
});

it('AC-4: adds the annual item to the unpaid monthly invoice of the same billing group', function () {
    $f = $this->f;
    tahunanFeeType($f, attributes: ['billing_group' => 'BULANAN']);
    $student = tagihanStudent($f);
    tagihanGenerate('2026-07-01');

    $result = tahunanGenerate($f->year);

    $invoice = tagihanInvoiceFor($student, 'BULANAN', '2026-07-01');

    expect($result['created_invoices'])->toBe(0)
        ->and($result['added_items'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(tagihanItems($invoice))->toHaveCount(2)
        ->and($invoice->total)->toBe(1250000);
});

it('AC-4: leaves a paid monthly invoice untouched and reports the annual item as locked', function () {
    $f = $this->f;
    $type = tahunanFeeType($f, attributes: ['billing_group' => 'BULANAN']);
    $student = tagihanStudent($f);
    tagihanGenerate('2026-07-01');
    $invoice = tagihanInvoiceFor($student, 'BULANAN', '2026-07-01');
    $invoice->forceFill(['paid_total' => 100000, 'status' => 'sebagian'])->save();

    $result = tahunanGenerate($f->year);

    $invoice->refresh();

    expect($result['added_items'])->toBe(0)
        ->and($result['skipped'])->toContain(['student_id' => $student->id, 'fee_type_id' => $type->id, 'reason' => 'invoice_locked'])
        ->and(tagihanItems($invoice))->toHaveCount(1)
        ->and($invoice->total)->toBe(500000);
});

it('AC-4: creates a separate invoice for a different billing group in the same period', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $student = tagihanStudent($f);
    tagihanGenerate('2026-07-01');

    $result = tahunanGenerate($f->year);

    expect($result['created_invoices'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->where('student_id', $student->id)->count())->toBe(2)
        ->and(tahunanInvoiceFor($student)->total)->toBe(750000)
        ->and(tagihanInvoiceFor($student, 'BULANAN', '2026-07-01')->total)->toBe(500000);
});

it('AC-5: the monthly generator joins the invoice made by the annual generator without touching the annual item', function () {
    $f = $this->f;
    tahunanFeeType($f, attributes: ['billing_group' => 'BULANAN']);
    $student = tagihanStudent($f);
    tahunanGenerate($f->year);
    $annualItem = InvoiceItem::query()->sole();

    $first = tagihanGenerate('2026-07-01');
    $second = tagihanGenerate('2026-07-01');

    $invoice = tagihanInvoiceFor($student, 'BULANAN', '2026-07-01');
    $items = tagihanItems($invoice);

    expect($first['created_invoices'])->toBe(0)
        ->and($first['added_items'])->toBe(1)
        ->and($second['added_items'])->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and($items)->toHaveCount(2)
        ->and($invoice->total)->toBe(1250000)
        ->and($items[$annualItem->fee_type_id]->id)->toBe($annualItem->id)
        ->and($items[$annualItem->fee_type_id]->base_amount)->toBe(750000)
        ->and($items[$annualItem->fee_type_id]->amount)->toBe(750000)
        ->and($items[$annualItem->fee_type_id]->description)->toBe('Daftar ulang 2026/2027');
});

it('AC-6: bills mukim and nonmukim annual fees only to the matching students', function (string $appliesTo, bool $residentBilled) {
    $f = $this->f;
    tahunanFeeType($f, attributes: ['applies_to' => $appliesTo]);
    $resident = tagihanStudent($f, resident: true);
    $commuter = tagihanStudent($f);

    tahunanGenerate($f->year);

    expect(tahunanInvoiceFor($resident) !== null)->toBe($residentBilled)
        ->and(tahunanInvoiceFor($commuter) !== null)->toBe(! $residentBilled);
})->with([
    'mukim' => [FeeType::APPLIES_TO_RESIDENT, true],
    'nonmukim' => [FeeType::APPLIES_TO_NON_RESIDENT, false],
]);

it('AC-7: bills only active students who entered by the end of the billing month', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $billed = [
        'entered at the start of the year' => tagihanStudent($f),
        'entered on the last day' => tagihanStudent($f, attributes: ['entered_on' => '2026-07-31']),
    ];
    $notBilled = [
        'entered next month' => tagihanStudent($f, attributes: ['entered_on' => '2026-08-01']),
        'left' => tagihanStudent($f, attributes: ['status' => 'keluar']),
        'graduated' => tagihanStudent($f, attributes: ['status' => 'lulus']),
    ];

    tahunanGenerate($f->year);

    foreach ($billed as $label => $student) {
        expect(tahunanInvoiceFor($student))->not->toBeNull("{$label} harus ditagih");
    }

    foreach ($notBilled as $label => $student) {
        expect(Invoice::withoutUnitScope()->where('student_id', $student->id)->exists())->toBeFalse("{$label} tidak boleh ditagih");
    }
});

it('AC-8: applies the three override modes to the annual fee', function (string $mode, int $value, array $expected) {
    $f = $this->f;
    $type = tahunanFeeType($f);
    $student = tagihanStudent($f);
    $override = keringananOverride(['student_id' => $student->id, 'fee_type_id' => $type->id, 'mode' => $mode, 'value' => $value, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    tahunanGenerate($f->year);

    $item = tagihanItems(tahunanInvoiceFor($student))->sole();

    expect($item->base_amount)->toBe($expected[0])
        ->and($item->discount_amount)->toBe($expected[1])
        ->and($item->amount)->toBe($expected[2])
        ->and($item->fee_override_id)->toBe($override->id);
})->with([
    'potongan' => ['potongan', 150000, [750000, 150000, 600000]],
    'persen' => ['persen', 20, [750000, 150000, 600000]],
    'nominal free' => ['nominal', 0, [750000, 750000, 0]],
]);

it('AC-8: skips and logs a student without a rate while others are still billed', function () {
    $f = $this->f;
    $type = tahunanFeeType($f);
    $billed = tagihanStudent($f);
    $unrated = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    Log::spy();

    $result = tahunanGenerate($f->year);

    expect(tahunanInvoiceFor($billed))->not->toBeNull()
        ->and(Invoice::withoutUnitScope()->where('student_id', $unrated->id)->exists())->toBeFalse()
        ->and($result['created_invoices'])->toBe(1)
        ->and($result['skipped'])->toContain(['student_id' => $unrated->id, 'fee_type_id' => $type->id, 'reason' => 'missing_rate']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['student_id'] ?? null) === $unrated->id
        && ($context['fee_type_id'] ?? null) === $type->id
        && ($context['reason'] ?? null) === 'missing_rate')->atLeast()->once();
});

it('AC-9: rejects a missing or invalid billing month setting', function (mixed $value) {
    $f = $this->f;
    tahunanFeeType($f, month: null);
    Setting::set('keuangan.annual_billing_month', $value);
    tagihanStudent($f);

    tagihanAssertRejected(fn () => tahunanGenerate($f->year), 'annual_month_not_set', 'academic_year_id');

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
})->with([
    'empty' => [null],
    'zero' => [0],
    'thirteen' => [13],
    'text' => ['abc'],
]);

it('AC-9: rejects a billing month outside the academic year', function () {
    $f = $this->f;
    tahunanFeeType($f, month: 3);
    $short = AcademicYear::factory()->create(['name' => '2026 Ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31']);
    tagihanStudent($f);

    tagihanAssertRejected(fn () => tahunanGenerate($short), 'annual_month_outside_year', 'academic_year_id');

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-9: refuses to run while the period lock is held', function () {
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);
    $lock = Cache::lock('keuangan:generate-invoices:2026-07', 60);
    expect($lock->get())->toBeTrue();

    tagihanAssertRejected(fn () => tahunanGenerate($f->year), 'already_running', 'academic_year_id');

    $lock->release();

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-10: logs one summary activity with frequency and academic year, caused by the panel user', function () {
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);
    $user = User::factory()->create();

    tahunanGenerate($f->year, $user);

    $activity = Activity::query()->where('log_name', 'keuangan')->where('event', 'generated')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties->toArray())->toMatchArray([
            'period' => '2026-07-01',
            'created_invoices' => 1,
            'frequency' => 'tahunan',
            'academic_year_id' => $f->year->id,
        ])
        ->and(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($user->id);
});

it('AC-12: bills again in the next academic year and leaves the previous annual invoice unchanged', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $student = tagihanStudent($f);
    tahunanGenerate($f->year);
    $previous = tahunanInvoiceFor($student)->only(['id', 'total', 'invoice_no', 'status']);
    $next = AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);

    $result = tahunanGenerate($next);

    $invoice = tahunanInvoiceFor($student, period: '2027-07-01');

    expect($result['created_invoices'])->toBe(1)
        ->and($invoice->academic_year_id)->toBe($next->id)
        ->and(tagihanItems($invoice)->sole()->description)->toBe('Daftar ulang 2027/2028')
        ->and(Invoice::withoutUnitScope()->where('student_id', $student->id)->count())->toBe(2)
        ->and(Invoice::withoutUnitScope()->find($previous['id'])->only(['id', 'total', 'invoice_no', 'status']))->toBe($previous);
});

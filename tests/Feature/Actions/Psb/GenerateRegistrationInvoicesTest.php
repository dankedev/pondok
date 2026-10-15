<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->f = pendaftaranFixture();
});

/**
 * Tagihan aktif pendaftar, urut id.
 *
 * @return Collection<int, Invoice>
 */
function pendaftaranInvoices(Applicant $applicant, bool $includeCancelled = false): Collection
{
    return Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)
        ->when(! $includeCancelled, fn ($query) => $query->where('status', '<>', 'batal'))
        ->orderBy('id')->get();
}

it('AC-14: bills the registration fee when applying', function () {
    $applicant = pendaftaranSubmit()['applicant'];
    $invoice = pendaftaranInvoices($applicant)->sole();
    $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->sole();

    expect($invoice)
        ->applicant_id->toBe($applicant->id)
        ->student_id->toBeNull()
        ->academic_year_id->toBe($this->f->y2027->id)
        ->billing_group->toBe('PSB_DAFTAR')
        ->total->toBe(250000)
        ->paid_total->toBe(0)
        ->status->toBe('belum')
        ->generated_by->toBeNull()
        ->invoice_no->toBe('INV-202610-00001')
        ->and($invoice->period->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_on->toDateString())->toBe('2026-12-31')
        ->and($item)
        ->fee_type_id->toBe($this->f->formulir->id)
        ->base_amount->toBe(250000)
        ->discount_amount->toBe(0)
        ->amount->toBe(250000)
        ->rate_source->toBe('jenjang')
        ->and($item->description)->toContain('Formulir pendaftaran')->toContain('Gelombang 1');
});

it('AC-14: continues from the highest existing invoice number', function () {
    Invoice::factory()->create(['invoice_no' => 'INV-202610-00007']);

    $applicant = pendaftaranSubmit()['applicant'];

    expect(pendaftaranInvoices($applicant)->sole()->invoice_no)->toBe('INV-202610-00008');
});

it('AC-14: uses today as the due date when the wave already closed', function () {
    $this->f->wave->update(['opens_on' => '2026-09-01', 'closes_on' => '2026-10-10']);
    $applicant = pendaftaranApplicant();

    app(GenerateRegistrationInvoices::class)->handle($applicant);

    expect(pendaftaranInvoices($applicant)->sole()->due_on->toDateString())->toBe('2026-10-15');
});

it('AC-14: records the user who generated the invoice', function () {
    $user = User::factory()->create();
    $applicant = pendaftaranApplicant();

    app(GenerateRegistrationInvoices::class)->handle($applicant, $user);

    expect(pendaftaranInvoices($applicant)->sole()->generated_by)->toBe($user->id);
});

it('AC-15: prefers a wave specific rate', function () {
    keuanganRate($this->f->formulir, $this->f->y2027, $this->f->sltp, 200000, waveId: $this->f->wave->id);

    $applicant = pendaftaranSubmit()['applicant'];
    $item = InvoiceItem::query()->where('invoice_id', pendaftaranInvoices($applicant)->sole()->id)->sole();

    expect($item->amount)->toBe(200000)->and($item->base_amount)->toBe(200000);
});

it('AC-15: puts fee types of the same group on one invoice', function () {
    $seragam = psbFeeType('saat_daftar', ['code' => 'SERAGAM', 'name' => 'Seragam', 'billing_group' => 'PSB_DAFTAR', 'priority' => 20]);
    keuanganRate($seragam, $this->f->y2027, $this->f->sltp, 100000);

    $applicant = pendaftaranSubmit()['applicant'];
    $invoice = pendaftaranInvoices($applicant)->sole();

    expect($invoice->total)->toBe(350000)
        ->and(InvoiceItem::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});

it('AC-15: creates one invoice per billing group', function () {
    $seragam = psbFeeType('saat_daftar', ['code' => 'SERAGAM', 'name' => 'Seragam', 'billing_group' => 'PSB_SERAGAM']);
    keuanganRate($seragam, $this->f->y2027, $this->f->sltp, 100000);

    $applicant = pendaftaranSubmit()['applicant'];
    $invoices = pendaftaranInvoices($applicant);

    expect($invoices)->toHaveCount(2)
        ->and($invoices->pluck('billing_group')->sort()->values()->all())->toBe(['PSB_DAFTAR', 'PSB_SERAGAM'])
        ->and($invoices->pluck('total')->sort()->values()->all())->toBe([100000, 250000])
        ->and($invoices->pluck('invoice_no')->unique())->toHaveCount(2);
});

it('AC-15: bills only active saat_daftar fee types', function () {
    $inactive = psbFeeType('saat_daftar', ['code' => 'LAMA', 'name' => 'Lama', 'is_active' => false]);
    $monthly = FeeType::factory()->monthly()->create(['code' => 'SPP', 'billing_group' => 'BULANAN']);
    $accepted = psbFeeType('saat_diterima', ['code' => 'PANGKAL', 'name' => 'Uang pangkal', 'billing_group' => 'PSB_DITERIMA']);
    foreach ([$inactive, $monthly, $accepted] as $type) {
        keuanganRate($type, $this->f->y2027, $this->f->sltp, 999000);
    }

    $applicant = pendaftaranSubmit()['applicant'];
    $invoice = pendaftaranInvoices($applicant)->sole();

    expect($invoice->total)->toBe(250000)
        ->and(InvoiceItem::query()->where('invoice_id', $invoice->id)->pluck('fee_type_id')->all())->toBe([$this->f->formulir->id]);
});

it('AC-15: skips a fee type without a rate and logs a warning', function () {
    $buku = psbFeeType('saat_daftar', ['code' => 'BUKU', 'name' => 'Buku']);
    $applicant = pendaftaranApplicant();
    Log::spy();

    $result = app(GenerateRegistrationInvoices::class)->handle($applicant);

    expect($result['skipped'])->toBe([['fee_type_id' => $buku->id, 'reason' => 'missing_rate']])
        ->and($result['created_invoices'])->toBe(1)
        ->and(InvoiceItem::query()->where('invoice_id', pendaftaranInvoices($applicant)->sole()->id)->pluck('fee_type_id')->all())->toBe([$this->f->formulir->id]);

    Log::shouldHaveReceived('warning');
});

it('AC-15: still saves the applicant when no fee type has a rate', function () {
    $this->f->formulir->rates()->delete();
    Log::spy();

    $applicant = pendaftaranSubmit()['applicant'];

    expect($applicant->exists)->toBeTrue()
        ->and(pendaftaranInvoices($applicant))->toHaveCount(0);
});

it('AC-15: applies a free override to the registration fee', function () {
    $applicant = pendaftaranApplicant();
    $override = FeeOverride::factory()->forApplicant($applicant->id)->free()->create(['fee_type_id' => $this->f->formulir->id, 'valid_from' => '2026-01-01']);

    app(GenerateRegistrationInvoices::class)->handle($applicant);
    $invoice = pendaftaranInvoices($applicant)->sole();
    $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->sole();

    expect($item)->base_amount->toBe(250000)->discount_amount->toBe(250000)->amount->toBe(0)->fee_override_id->toBe($override->id)
        ->and($invoice)->total->toBe(0)->status->toBe('lunas');
});

it('AC-16: is idempotent', function () {
    $applicant = pendaftaranApplicant();
    $action = app(GenerateRegistrationInvoices::class);

    $first = $action->handle($applicant);
    $second = $action->handle($applicant);

    expect($first['created_invoices'])->toBe(1)
        ->and($second['created_invoices'])->toBe(0)
        ->and($second['existing_invoices'])->toBe(1)
        ->and(pendaftaranInvoices($applicant))->toHaveCount(1);
});

it('AC-16: keeps the snapshot of an existing invoice when the rate changes', function () {
    $applicant = pendaftaranApplicant();
    app(GenerateRegistrationInvoices::class)->handle($applicant);
    keuanganRate($this->f->formulir, $this->f->y2027, $this->f->sltp, 400000, waveId: $this->f->wave->id);

    app(GenerateRegistrationInvoices::class)->handle($applicant);

    expect(pendaftaranInvoices($applicant)->sole()->total)->toBe(250000);
});

it('AC-16: creates a new invoice with a new number after cancelling', function () {
    $applicant = pendaftaranApplicant();
    $action = app(GenerateRegistrationInvoices::class);
    $action->handle($applicant);
    $first = pendaftaranInvoices($applicant)->sole();
    app(CancelInvoice::class)->handle($first, User::factory()->create(), 'Salah tagih');

    $result = $action->handle($applicant);
    $active = pendaftaranInvoices($applicant)->sole();

    expect($result['created_invoices'])->toBe(1)
        ->and($active->id)->not->toBe($first->id)
        ->and($active->invoice_no)->not->toBe($first->invoice_no)
        ->and(pendaftaranInvoices($applicant, includeCancelled: true))->toHaveCount(2);
});

it('AC-16: refuses closed applicants', function (string $status) {
    $applicant = pendaftaranApplicant(['status' => $status]);

    pendaftaranAssertRejected(fn () => app(GenerateRegistrationInvoices::class)->handle($applicant), 'applicant_closed');

    expect(pendaftaranInvoices($applicant, includeCancelled: true))->toHaveCount(0);
})->with(['ditolak', 'diterima', 'cadangan', 'draft']);

it('AC-16: bills a verified applicant', function () {
    $applicant = pendaftaranApplicant(['status' => 'diverifikasi']);

    app(GenerateRegistrationInvoices::class)->handle($applicant);

    expect(pendaftaranInvoices($applicant))->toHaveCount(1);
});

it('AC-16: skips invoices at application time when the finance module is off', function () {
    Setting::set('modules.keuangan.enabled', false);

    $applicant = pendaftaranSubmit()['applicant'];

    expect($applicant->exists)->toBeTrue()
        ->and(pendaftaranInvoices($applicant, includeCancelled: true))->toHaveCount(0);
});

it('AC-11a: fails without a half finished invoice when the number always conflicts', function () {
    Invoice::factory()->create(['invoice_no' => 'INV-202610-00001']);
    pendaftaranFakeInvoiceNumber(always: 'INV-202610-00001');
    $applicant = pendaftaranApplicant();
    Log::spy();

    expect(fn () => app(GenerateRegistrationInvoices::class)->handle($applicant))->toThrow(RuntimeException::class)
        ->and(pendaftaranInvoices($applicant, includeCancelled: true))->toHaveCount(0)
        ->and(InvoiceItem::query()->count())->toBe(0);

    Log::shouldHaveReceived('error');
});

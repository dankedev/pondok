<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Psb\AcceptApplicant;
use App\Actions\Psb\ApplyTrackScholarship;
use App\Actions\Psb\GenerateAcceptanceInvoices;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = seleksiFixture();
});

/**
 * Tagihan penerimaan aktif (bukan formulir), urut id.
 */
function seleksiAcceptanceInvoices(Applicant $applicant, bool $includeCancelled = false): Collection
{
    return seleksiInvoices($applicant, $includeCancelled)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB'])->values();
}

it('AC-11: bills the acceptance fees per billing group', function () {
    $applicant = seleksiApplicant();
    $registration = seleksiInvoices($applicant, group: 'PSB_DAFTAR')->sole();
    $registrationBefore = $registration->only(['invoice_no', 'total', 'paid_total', 'status', 'period', 'due_on']);

    $result = seleksiAccept($applicant, $this->f->user);

    $invoices = seleksiAcceptanceInvoices($applicant);
    $terima = $invoices->firstWhere('billing_group', 'PSB_TERIMA');
    $kitab = $invoices->firstWhere('billing_group', 'PSB_KITAB');
    $items = seleksiItems($terima);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diterima')
        ->and($result['applicant'])->toBeInstanceOf(Applicant::class)
        ->and($result['billing']['created_invoices'])->toBe(2)
        ->and($result['billing_failed'])->toBeFalse()
        ->and(seleksiOverrides($applicant))->toHaveCount(0)
        ->and($invoices)->toHaveCount(2)
        ->and($terima)
        ->student_id->toBeNull()
        ->academic_year_id->toBe($this->f->y2027->id)
        ->total->toBe(5750000)
        ->paid_total->toBe(0)
        ->status->toBe('belum')
        ->generated_by->toBe($this->f->user->id)
        ->invoice_no->toBe('INV-202611-00001')
        ->and($terima->period->toDateString())->toBe('2026-11-01')
        ->and($terima->due_on->toDateString())->toBe('2027-07-01')
        ->and($kitab)->total->toBe(300000)->status->toBe('belum')->invoice_no->toBe('INV-202611-00002')
        ->and($items->keys()->all())->toBe(['UANG_PANGKAL', 'SERAGAM'])
        ->and($items['UANG_PANGKAL'])->base_amount->toBe(5000000)->discount_amount->toBe(0)->amount->toBe(5000000)->rate_source->toBe('jenjang')
        ->and($items['SERAGAM'])->base_amount->toBe(750000)->amount->toBe(750000)
        ->and($items['UANG_PANGKAL']->description)->toContain('Uang pangkal')->toContain('Gelombang 1')
        ->and(seleksiItems($kitab)['KITAB']->amount)->toBe(300000)
        ->and($registration->fresh()->only(['invoice_no', 'total', 'paid_total', 'status', 'period', 'due_on']))->toEqual($registrationBefore);
});

it('AC-11: leaves the due date at today when the academic year already started', function () {
    $this->f->y2027->update(['starts_on' => '2026-11-01', 'ends_on' => '2027-10-31']);
    $applicant = seleksiApplicant();

    seleksiAccept($applicant, $this->f->user);

    expect(seleksiAcceptanceInvoices($applicant)->first()->due_on->toDateString())->toBe('2026-11-20');
});

it('AC-12: applies the track scholarship as applicant overrides and discounts the items', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');

    seleksiAccept($applicant, $this->f->user);

    $overrides = seleksiOverrides($applicant);
    $terima = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA');
    $items = seleksiItems($terima);
    $pangkal = $overrides->firstWhere('fee_type_id', $this->f->uangPangkal->id);

    expect($overrides)->toHaveCount(2)
        ->and($overrides->pluck('feeType.code')->sort()->values()->all())->toBe(['SPP', 'UANG_PANGKAL']);

    foreach ($overrides as $override) {
        expect($override)
            ->applicant_id->toBe($applicant->id)
            ->student_id->toBeNull()
            ->scholarship_id->toBe($this->f->tahfidz->id)
            ->mode->toBe('persen')
            ->value->toBe(50)
            ->approved_by->toBe($this->f->user->id)
            ->and($override->valid_from->toDateString())->toBe('2026-11-20')
            ->and($override->valid_until)->toBeNull()
            ->and($override->reason)->toContain('Tahfidz')
            ->and(Activity::query()->where('subject_type', $override->getMorphClass())->where('subject_id', $override->id)->where('event', 'created')->first())
            ->log_name->toBe('keuangan')->causer_id->toBe($this->f->user->id);
    }

    expect($terima->total)->toBe(3250000)
        ->and($items['UANG_PANGKAL'])->base_amount->toBe(5000000)->discount_amount->toBe(2500000)->amount->toBe(2500000)->fee_override_id->toBe($pangkal->id)
        ->and($items['SERAGAM'])->discount_amount->toBe(0)->amount->toBe(750000)->fee_override_id->toBeNull();
});

it('AC-13: gives every acceptance fee for free on the nominal zero scholarship without a mapping', function () {
    $applicant = seleksiApplicant('diverifikasi', 'YATIM');

    seleksiAccept($applicant, $this->f->user);

    $overrides = seleksiOverrides($applicant);
    $invoices = seleksiAcceptanceInvoices($applicant);

    expect($overrides->pluck('feeType.code')->sort()->values()->all())->toBe(['KITAB', 'SERAGAM', 'UANG_PANGKAL'])
        ->and($overrides->every(fn (FeeOverride $override): bool => $override->mode === 'nominal' && $override->value === 0 && $override->scholarship_id === $this->f->yatim->id))->toBeTrue()
        ->and($invoices)->toHaveCount(2)
        ->and($invoices->every(fn (Invoice $invoice): bool => $invoice->total === 0 && $invoice->status === 'lunas'))->toBeTrue();

    foreach ($invoices as $invoice) {
        foreach (seleksiItems($invoice) as $item) {
            expect($item->amount)->toBe(0)->and($item->discount_amount)->toBe($item->base_amount);
        }
    }

    expect($overrides->pluck('feeType.code')->all())->not->toContain('SPP');
});

it('AC-14: keeps a custom override and skips only that fee type', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    $custom = FeeOverride::factory()->forApplicant($applicant->id)->discount(1000000)->between('2026-11-01', null)->create([
        'fee_type_id' => $this->f->uangPangkal->id,
        'scholarship_id' => null,
    ]);

    $result = seleksiAccept($applicant, $this->f->user);

    $overrides = seleksiOverrides($applicant);
    $items = seleksiItems(seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA'));

    expect($overrides)->toHaveCount(2)
        ->and($overrides->pluck('feeType.code')->sort()->values()->all())->toBe(['SPP', 'UANG_PANGKAL'])
        ->and($overrides->firstWhere('fee_type_id', $this->f->uangPangkal->id)->id)->toBe($custom->id)
        ->and(seleksiSkipped($result['billing']['scholarship']['skipped'], $this->f->uangPangkal->id, 'existing_override'))->toBeTrue()
        ->and($items['UANG_PANGKAL'])->amount->toBe(4000000)->discount_amount->toBe(1000000)->fee_override_id->toBe($custom->id);
});

it('AC-14: skips an inactive scholarship with a warning and still bills without a discount', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    $this->f->tahfidz->update(['is_active' => false]);
    Log::spy();

    $result = seleksiAccept($applicant, $this->f->user);

    $terima = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA');

    expect(seleksiOverrides($applicant))->toHaveCount(0)
        ->and(seleksiSkipped($result['billing']['scholarship']['skipped'], null, 'scholarship_inactive'))->toBeTrue()
        ->and($terima->total)->toBe(5750000);

    Log::shouldHaveReceived('warning');
});

it('AC-14: does nothing for a track without a scholarship', function () {
    $applicant = seleksiApplicant('diverifikasi', 'REGULER');

    $result = seleksiAccept($applicant, $this->f->user);

    expect($result['billing']['scholarship'])->toBe(['created' => [], 'skipped' => []]);
});

it('AC-15: keeps overrides and invoice items as snapshots', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);

    $snapshot = fn (): array => [
        FeeOverride::query()->where('applicant_id', $applicant->id)->orderBy('id')->get(['id', 'mode', 'value', 'scholarship_id', 'valid_from', 'valid_until'])->toArray(),
        seleksiAcceptanceInvoices($applicant)->map(fn (Invoice $invoice): array => [
            $invoice->only(['id', 'total', 'status']),
            InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->get(['fee_type_id', 'base_amount', 'discount_amount', 'amount', 'fee_override_id'])->toArray(),
        ])->toArray(),
    ];
    $before = $snapshot();

    $this->f->tahfidz->update(['mode' => 'persen', 'default_value' => 100]);
    seleksiMapFeeType($this->f->tahfidzTrack, $this->f->kitab);
    $this->f->rates['UANG_PANGKAL']->update(['amount' => 9000000]);

    expect($snapshot())->toBe($before);
});

it('AC-16: is idempotent when billing runs again', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $invoices = seleksiAcceptanceInvoices($applicant)->pluck('id')->all();
    $overrides = seleksiOverrides($applicant)->pluck('id')->all();

    $again = app(GenerateAcceptanceInvoices::class)->handle(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user);

    expect($again['existing_invoices'])->toBe(2)
        ->and($again['created_invoices'])->toBe(0)
        ->and(seleksiSkipped($again['scholarship']['skipped'], $this->f->uangPangkal->id, 'already_applied'))->toBeTrue()
        ->and(seleksiSkipped($again['scholarship']['skipped'], $this->f->spp->id, 'already_applied'))->toBeTrue()
        ->and(seleksiAcceptanceInvoices($applicant)->pluck('id')->all())->toBe($invoices)
        ->and(seleksiOverrides($applicant)->pluck('id')->all())->toBe($overrides);
});

it('AC-16: refuses a second acceptance without new invoices or overrides', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiAccept(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user), 'invalid_transition', 'status');

    expect(seleksiSnapshot($applicant))->toBe($before);
});

it('AC-16: bills only the cancelled group again with a new number', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    $kitab = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_KITAB');
    $terima = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA');

    app(CancelInvoice::class)->handle($kitab, User::factory()->create(), 'Salah tagih');

    $result = app(GenerateAcceptanceInvoices::class)->handle(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user);

    $active = seleksiAcceptanceInvoices($applicant);
    $newKitab = $active->firstWhere('billing_group', 'PSB_KITAB');

    expect($result['created_invoices'])->toBe(1)
        ->and($result['existing_invoices'])->toBe(1)
        ->and($active)->toHaveCount(2)
        ->and($newKitab->id)->not->toBe($kitab->id)
        ->and($newKitab->invoice_no)->toBe('INV-202611-00003')
        ->and($active->firstWhere('billing_group', 'PSB_TERIMA')->id)->toBe($terima->id)
        ->and($terima->fresh()->status)->toBe('belum')
        ->and(Invoice::withoutUnitScope()->whereKey($kitab->id)->value('status'))->toBe('batal');
});

it('AC-17: skips a fee type without a rate and logs a warning', function () {
    FeeRate::query()->where('fee_type_id', $this->f->kitab->id)->delete();
    $applicant = seleksiApplicant();
    Log::spy();

    $result = seleksiAccept($applicant, $this->f->user);

    expect(seleksiAcceptanceInvoices($applicant)->pluck('billing_group')->all())->toBe(['PSB_TERIMA'])
        ->and(seleksiSkipped($result['billing']['skipped'], $this->f->kitab->id, 'missing_rate'))->toBeTrue()
        ->and($result['billing']['created_invoices'])->toBe(1)
        ->and($result['billing_failed'])->toBeFalse();

    Log::shouldHaveReceived('warning');
});

it('AC-17: prefers the wave specific rate', function () {
    keuanganRate($this->f->uangPangkal, $this->f->y2027, $this->f->sltp, 4500000, waveId: $this->f->wave->id);
    $applicant = seleksiApplicant();

    seleksiAccept($applicant, $this->f->user);

    $items = seleksiItems(seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA'));

    expect($items['UANG_PANGKAL'])->base_amount->toBe(4500000)->amount->toBe(4500000);
});

it('AC-17: bills only active acceptance fee types', function () {
    $this->f->seragam->update(['is_active' => false]);
    $applicant = seleksiApplicant();

    seleksiAccept($applicant, $this->f->user);

    $codes = seleksiAcceptanceInvoices($applicant)->flatMap(fn (Invoice $invoice) => seleksiItems($invoice)->keys())->sort()->values()->all();

    expect($codes)->toBe(['KITAB', 'UANG_PANGKAL'])
        ->and(seleksiInvoices($applicant)->flatMap(fn (Invoice $invoice) => seleksiItems($invoice)->keys())->all())->not->toContain('SPP');
});

it('AC-17: accepts without billing while the finance module is off and bills after it is turned on', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    Setting::set('modules.keuangan.enabled', false);

    $result = seleksiAccept($applicant, $this->f->user);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diterima')
        ->and($result['billing'])->toBeNull()
        ->and($result['billing_failed'])->toBeFalse()
        ->and(seleksiOverrides($applicant))->toHaveCount(0)
        ->and(seleksiAcceptanceInvoices($applicant))->toHaveCount(0);

    Setting::set('modules.keuangan.enabled', true);

    $billing = app(GenerateAcceptanceInvoices::class)->handle(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user);

    expect($billing['created_invoices'])->toBe(2)
        ->and(seleksiOverrides($applicant))->toHaveCount(2)
        ->and(seleksiAcceptanceInvoices($applicant))->toHaveCount(2);
});

it('AC-17: refuses to bill an applicant that is not accepted', function (string $status) {
    $applicant = seleksiApplicant($status);
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user), 'applicant_not_accepted');

    expect(seleksiSnapshot($applicant))->toBe($before);
})->with(['diverifikasi', 'ditolak', 'diajukan', 'cadangan']);

it('AC-18: keeps the acceptance when the invoice number keeps clashing', function () {
    $applicant = seleksiApplicant();
    $used = seleksiInvoices($applicant)->first()->invoice_no;
    pendaftaranFakeInvoiceNumber(always: $used);
    Log::spy();

    $result = seleksiAccept($applicant, $this->f->user);

    expect($result['billing_failed'])->toBeTrue()
        ->and(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diterima')
        ->and(seleksiAcceptanceInvoices($applicant, true))->toHaveCount(0)
        ->and(InvoiceItem::query()->count())->toBe(1);

    Log::shouldHaveReceived('error');
});

it('AC-18: throws from the generator when the invoice number keeps clashing', function () {
    $applicant = seleksiApplicant('diterima');
    $used = seleksiInvoices($applicant)->first()->invoice_no;
    $fake = pendaftaranFakeInvoiceNumber(always: $used);
    Log::spy();

    expect(fn () => app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user))->toThrow(RuntimeException::class)
        ->and($fake->calls)->toBe(3)
        ->and(seleksiAcceptanceInvoices($applicant, true))->toHaveCount(0);

    Log::shouldHaveReceived('error');
});

it('AC-18: stores the invoice with the next number after one clash', function () {
    $applicant = seleksiApplicant('diterima');
    $used = seleksiInvoices($applicant)->first()->invoice_no;
    $fake = pendaftaranFakeInvoiceNumber(forced: [$used]);

    $result = app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user);

    expect($result['created_invoices'])->toBe(2)
        ->and($fake->calls)->toBe(3)
        ->and(seleksiAcceptanceInvoices($applicant)->pluck('invoice_no')->all())->toBe(['INV-202611-00001', 'INV-202611-00002']);
});

it('AC-18a: refuses to bill or apply a scholarship from a stale instance', function () {
    $stale = seleksiApplicant('diterima', 'TAHFIDZ');
    DB::table('applicants')->where('id', $stale->id)->update(['status' => 'ditolak']);

    pendaftaranAssertRejected(fn () => app(GenerateAcceptanceInvoices::class)->handle($stale, $this->f->user), 'applicant_not_accepted');
    pendaftaranAssertRejected(fn () => app(ApplyTrackScholarship::class)->handle($stale, $this->f->user), 'applicant_not_accepted');

    expect(seleksiAcceptanceInvoices($stale, true))->toHaveCount(0)
        ->and(seleksiOverrides($stale))->toHaveCount(0);
});

it('AC-18a: refuses to bill or apply a scholarship for a soft deleted applicant', function () {
    $stale = seleksiApplicant('diterima', 'TAHFIDZ');
    DB::table('applicants')->where('id', $stale->id)->update(['deleted_at' => now()]);

    pendaftaranAssertRejected(fn () => app(GenerateAcceptanceInvoices::class)->handle($stale, $this->f->user), 'applicant_not_accepted');
    pendaftaranAssertRejected(fn () => app(ApplyTrackScholarship::class)->handle($stale, $this->f->user), 'applicant_not_accepted');

    expect(seleksiAcceptanceInvoices($stale, true))->toHaveCount(0)
        ->and(seleksiOverrides($stale))->toHaveCount(0);
});

it('AC-18a: stops billing the next group when the status changes between groups', function () {
    $applicant = seleksiApplicant('diterima');
    $fake = seleksiFakeInvoiceNumberWithHook(fn () => DB::afterCommit(fn () => DB::table('applicants')->where('id', $applicant->id)->update(['status' => 'ditolak'])));

    pendaftaranAssertRejected(fn () => app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user), 'applicant_not_accepted');

    $active = seleksiAcceptanceInvoices($applicant);

    expect($fake->calls)->toBe(1)
        ->and($active->pluck('billing_group')->all())->toBe(['PSB_TERIMA'])
        ->and(DB::table('applicants')->where('id', $applicant->id)->value('status'))->toBe('ditolak');
});

it('AC-18a: reports a billing failure when the status changes between groups during acceptance', function () {
    $applicant = seleksiApplicant('diverifikasi');
    seleksiFakeInvoiceNumberWithHook(fn () => DB::afterCommit(fn () => DB::table('applicants')->where('id', $applicant->id)->update(['status' => 'ditolak'])));
    Log::spy();

    $result = seleksiAccept($applicant, $this->f->user);

    expect($result['billing_failed'])->toBeTrue()
        ->and(seleksiAcceptanceInvoices($applicant)->pluck('billing_group')->all())->toBe(['PSB_TERIMA']);

    Log::shouldHaveReceived('error');
});

it('AC-19: allocates a first installment to the highest priority item', function () {
    $applicant = seleksiApplicant();
    seleksiAccept($applicant, $this->f->user);
    $terima = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_TERIMA');

    bayarRecord($terima, 1000000);

    $acceptance = $applicant->fresh()->acceptanceInvoices()->orderBy('id')->get();

    expect(bayarPaidByCode($terima))->toBe(['UANG_PANGKAL' => 1000000, 'SERAGAM' => 0])
        ->and($terima->fresh())->status->toBe('sebagian')->paid_total->toBe(1000000)
        ->and($acceptance->pluck('id')->all())->toBe(seleksiAcceptanceInvoices($applicant)->pluck('id')->all())
        ->and($applicant->fresh()->registrationInvoices()->pluck('billing_group')->all())->toBe(['PSB_DAFTAR'])
        ->and($applicant->fresh()->acceptanceInvoices()->pluck('billing_group')->sort()->values()->all())->toBe(['PSB_KITAB', 'PSB_TERIMA']);
});

it('AC-19: leaves cancelled invoices out of the acceptance and registration relations', function () {
    $applicant = seleksiApplicant();
    seleksiAccept($applicant, $this->f->user);
    $kitab = seleksiAcceptanceInvoices($applicant)->firstWhere('billing_group', 'PSB_KITAB');

    app(CancelInvoice::class)->handle($kitab, User::factory()->create(), 'Salah tagih');

    expect($applicant->fresh()->acceptanceInvoices()->pluck('billing_group')->all())->toBe(['PSB_TERIMA']);
});

it('AC-11: resolves the accept action from the container', function () {
    expect(app(AcceptApplicant::class))->toBeInstanceOf(AcceptApplicant::class);
});

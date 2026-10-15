<?php

use App\Actions\Keuangan\CancelPayment;
use App\Filament\Resources\FeeOverrides\FeeOverrideResource;
use App\Filament\Resources\FeeRates\FeeRateResource;
use App\Filament\Resources\FeeTypes\FeeTypeResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Scholarships\ScholarshipResource;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
});

/**
 * Pembayaran 500.000 atas tagihan santri SLTA (jenjang lain dari fixture SLTP).
 */
function paymentOfSlta(object $f, string $receiptNo = 'KW-202610-00900'): Payment
{
    $invoice = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'SLTA', 'total' => 500000]);

    return Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500000, 'receipt_no' => $receiptNo]);
}

/**
 * Banyaknya query yang dijalankan saat daftar pembayaran dirender.
 */
function paymentListQueryCount(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListPayments::class);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('AC-29: scopes payments to the user\'s units', function () {
    $f = $this->f;
    $own = bayarRecord(bayarInvoice($f), 500000);
    $archivedStudent = tagihanStudent($f, resident: true);
    tagihanGenerate();
    $archivedInvoice = tagihanInvoiceFor($archivedStudent);
    $archived = bayarRecord($archivedInvoice, 100000);
    $archivedStudent->delete();
    $other = paymentOfSlta($f);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$own, $archived])
        ->assertCanNotSeeTableRecords([$other]);

    $this->get("/admin/payments/{$own->id}")->assertOk();
    $this->get("/admin/payments/{$other->id}")->assertNotFound();

    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$own, $archived, $other]);
    $this->get("/admin/payments/{$other->id}")->assertOk();
});

it('AC-29: scopes the unit filter options and the unit filter itself', function () {
    $f = $this->f;
    $own = bayarRecord(bayarInvoice($f), 500000);
    $other = paymentOfSlta($f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)
        ->filterTable('unit', $f->slta->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$own]);
});

it('AC-29: lists and opens payments of invoices without a student', function () {
    $applicantInvoice = Invoice::factory()->create(['academic_year_id' => $this->f->year->id, 'student_id' => null, 'applicant_id' => Applicant::factory()->create()->id, 'billing_group' => 'PSB', 'total' => 300000]);
    $payment = Payment::factory()->create(['invoice_id' => $applicantInvoice->id, 'amount' => 300000]);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$payment]);
    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertOk();
});

it('AC-31: orders the keuangan menu with payments right below invoices', function () {
    expect(InvoiceResource::getNavigationSort())->toBe(0)
        ->and(PaymentResource::getNavigationSort())->toBe(1)
        ->and(FeeTypeResource::getNavigationSort())->toBe(2)
        ->and(FeeRateResource::getNavigationSort())->toBe(3)
        ->and(ScholarshipResource::getNavigationSort())->toBe(4)
        ->and(FeeOverrideResource::getNavigationSort())->toBe(5);
});

it('AC-38: cancels a payment from the list', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('cancel')->table($payment), ['reason' => 'Salah input'])
        ->assertHasNoFormErrors();

    $stored = $payment->fresh();

    expect($stored->status)->toBe('batal')
        ->and($stored->cancel_reason)->toBe('Salah input')
        ->and(Invoice::withoutUnitScope()->find($payment->invoice_id)->paid_total)->toBe(0)
        ->and(asramaNotifications()->map(fn (array $n): string => ($n['status'] ?? '').'|'.($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n"))
        ->toContain('success|')->toContain($payment->receipt_no);
});

it('AC-38: cancels a payment from the detail page', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->callAction('cancel', ['reason' => 'Salah input'])
        ->assertHasNoFormErrors();

    expect($payment->fresh()->status)->toBe('batal');
});

it('AC-38: requires a reason to cancel', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('cancel')->table($payment), ['reason' => ''])
        ->assertHasFormErrors(['reason']);

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->callAction('cancel', ['reason' => str_repeat('a', 256)])
        ->assertHasFormErrors(['reason']);

    expect($payment->fresh()->status)->toBe('diterima');
});

it('AC-38: hides cancel for cancelled payments and without the cancel permission', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $cancelled = bayarRecord(bayarInvoice($this->f, tagihanStudent($this->f, resident: true)), 100000);
    app(CancelPayment::class)->handle($cancelled, keuanganUser('bendahara'), 'Salah');

    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)
        ->assertActionVisible(TestAction::make('cancel')->table($payment))
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled));
    Livewire::test(ViewPayment::class, ['record' => $cancelled->id])->assertActionHidden('cancel');

    $viewer = User::factory()->create()->givePermissionTo(['view-any:payment', 'view:payment']);
    $this->actingAs($viewer);

    Livewire::test(ListPayments::class)->assertActionHidden(TestAction::make('cancel')->table($payment));
    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertActionHidden('cancel');

    expect($payment->fresh()->status)->toBe('diterima');
});

it('AC-38: refuses to run cancel without the cancel permission', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $viewer = User::factory()->create()->givePermissionTo(['view-any:payment', 'view:payment']);
    $this->actingAs($viewer);

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->assertActionHidden('cancel')
        ->mountAction('cancel')
        ->assertActionNotMounted('cancel');

    expect($payment->fresh()->status)->toBe('diterima');
});

it('AC-27: has no edit or delete actions on payments', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ListPayments::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($payment))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($payment))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('edit');
});

it('AC-39: formats and filters payments', function () {
    $f = $this->f;
    $cash = bayarRecord(bayarInvoice($f), 500000, ['paid_on' => '2026-10-15']);
    $transfer = bayarRecord(bayarInvoice($f, tagihanStudent($f, resident: true)), 300000, ['method' => 'transfer', 'paid_on' => '2026-09-20']);
    $cancelled = bayarRecord(bayarInvoice($f, tagihanStudent($f, resident: true)), 200000, ['paid_on' => '2026-10-10']);
    app(CancelPayment::class)->handle($cancelled, keuanganUser('bendahara'), 'Salah');
    $other = paymentOfSlta($f);
    $other->forceFill(['paid_on' => '2026-10-12'])->save();
    $this->actingAs(keuanganUser('bendahara'));

    $page = Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$cash, $transfer, $cancelled, $other]);
    $text = tagihanPlainText($page);

    expect($text)->toContain('Rp 500.000')
        ->toContain('15 Okt 2026')
        ->toContain('Tunai')
        ->toContain('Transfer')
        ->toContain($cash->receipt_no)
        ->toContain($cash->invoice->student->name)
        ->toContain(__('pondok.payment.statuses.diterima'))
        ->toContain(__('pondok.payment.statuses.batal'));

    $page->filterTable('status', 'batal')
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$cash, $transfer, $other])
        ->removeTableFilter('status')
        ->filterTable('method', 'transfer')
        ->assertCanSeeTableRecords([$transfer])
        ->assertCanNotSeeTableRecords([$cash, $cancelled])
        ->removeTableFilter('method')
        ->filterTable('unit', $f->slta->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$cash, $transfer, $cancelled])
        ->removeTableFilter('unit')
        ->filterTable('paid_on', ['paid_from' => '2026-10-11', 'paid_until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$cash, $other])
        ->assertCanNotSeeTableRecords([$transfer, $cancelled])
        ->removeTableFilter('paid_on')
        ->filterTable('paid_on', ['paid_from' => null, 'paid_until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$transfer])
        ->assertCanNotSeeTableRecords([$cash, $cancelled, $other]);
});

it('AC-39: searches by receipt number, NIS and student name', function () {
    $f = $this->f;
    $a = bayarRecord(bayarInvoice($f, tagihanStudent($f, resident: true, attributes: ['name' => 'Zainal Abidin', 'nis' => '7770001'])), 100000);
    $b = bayarRecord(bayarInvoice($f, tagihanStudent($f, resident: true, attributes: ['name' => 'Yusuf Hakim', 'nis' => '8880002'])), 100000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListPayments::class)
        ->searchTable('Zainal')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable('8880002')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->searchTable($a->receipt_no)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

it('AC-39: shows the empty state', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $text = tagihanPlainText(Livewire::test(ListPayments::class));

    expect(__('pondok.payment.empty'))->not->toBe('pondok.payment.empty')
        ->and($text)->not->toContain('pondok.payment.')
        ->toContain('Belum ada pembayaran.')
        ->toContain('Pembayaran dicatat dari halaman tagihan.');
});

it('AC-39: shows allocations and the amount in words on the detail page', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000, ['paid_on' => '2026-10-15', 'note' => 'Titipan wali'], keuanganUser('bendahara'));
    $items = $payment->invoice->items()->with('feeType')->get()->keyBy(fn ($item) => $item->feeType->code);
    $this->actingAs(keuanganUser('bendahara'));

    $text = tagihanPlainText(Livewire::test(ViewPayment::class, ['record' => $payment->id]));

    expect($text)->toContain('KW-202610-00001')
        ->toContain('Rp 500.000')
        ->toContain('Lima ratus ribu rupiah')
        ->toContain('15 Okt 2026')
        ->toContain($payment->invoice->invoice_no)
        ->toContain($payment->invoice->student->name)
        ->toContain($payment->invoice->student->nis)
        ->toContain('Oktober 2026')
        ->toContain('Titipan wali')
        ->toContain($items['MAKAN']->description)
        ->toContain('Rp 400.000')
        ->toContain($items['ASRAMA']->description)
        ->toContain('Rp 100.000')
        ->not->toContain($items['SPP']->description);
});

it('AC-39: shows cancellation details of a cancelled payment', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $admin = keuanganUser('bendahara');
    $admin->forceFill(['name' => 'Petugas Pembatal'])->save();
    app(CancelPayment::class)->handle($payment, $admin, 'Nominal salah ketik');
    $this->actingAs($admin);

    $text = tagihanPlainText(Livewire::test(ViewPayment::class, ['record' => $payment->id]));

    expect($text)->toContain('Nominal salah ketik')->toContain('Petugas Pembatal')->toContain(__('pondok.payment.statuses.batal'));
});

it('AC-39: offers print and proof download actions on the detail page', function () {
    $plain = bayarRecord(bayarInvoice($this->f), 100000);
    $withProof = bayarRecord(bayarInvoice($this->f, tagihanStudent($this->f, resident: true)), 100000, ['method' => 'transfer']);
    $withProof->forceFill(['proof_path' => 'payment-proofs/2026/10/x.jpg'])->save();
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewPayment::class, ['record' => $plain->id])
        ->assertActionVisible('printReceipt')
        ->assertActionHidden('downloadProof');
    Livewire::test(ViewPayment::class, ['record' => $withProof->id])
        ->assertActionVisible('printReceipt')
        ->assertActionVisible('downloadProof');

    expect(PaymentResource::receiptUrl($plain))->toBe(url("/admin/receipts/{$plain->id}"))
        ->and(PaymentResource::proofUrl($withProof))->toBe(url("/admin/payment-proofs/{$withProof->id}"));
});

it('AC-39: renders the list without a query per payment', function () {
    $f = $this->f;
    $invoice = bayarInvoice($f);
    bayarRecord($invoice, 100000);
    $this->actingAs(keuanganUser('bendahara'));
    $few = paymentListQueryCount();

    foreach (range(1, 8) as $i) {
        bayarRecord($invoice, 10000);
    }

    expect(paymentListQueryCount())->toBeLessThanOrEqual($few + 1);
});

it('AC-47: has no raw lang keys', function () {
    $payment = bayarRecord(bayarInvoice($this->f), 500000);
    $this->actingAs(keuanganUser('bendahara'));

    $list = Livewire::test(ListPayments::class);
    $detail = Livewire::test(ViewPayment::class, ['record' => $payment->id]);
    $cancel = Livewire::test(ListPayments::class)->mountAction(TestAction::make('cancel')->table($payment));

    foreach ([tagihanPlainText($list), tagihanPlainText($detail), tagihanPlainText($cancel)] as $text) {
        expect($text)->not->toContain('pondok.payment.')->not->toContain('pondok.receipt.')->not->toContain('pondok.navigation.');
    }
});

it('AC-39: shows the applicant owner in the payment list and detail', function () {
    $applicant = Applicant::factory()->create(['unit_id' => $this->f->sltp->id, 'registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan']);
    $invoice = Invoice::factory()->create(['academic_year_id' => $this->f->year->id, 'student_id' => null, 'applicant_id' => $applicant->id, 'billing_group' => 'PSB_DAFTAR', 'total' => 250000]);
    $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250000]);
    $this->actingAs(keuanganUser('bendahara'));

    $list = Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$payment]);

    expect(tagihanPlainText($list))->toContain('PSB-2027-0001')->toContain('Ahmad Fauzan')
        ->and(tagihanPlainText(Livewire::test(ViewPayment::class, ['record' => $payment->id])))->toContain('PSB-2027-0001 — Ahmad Fauzan');

    Livewire::test(ListPayments::class)->searchTable('PSB-2027-0001')->assertCanSeeTableRecords([$payment]);
});

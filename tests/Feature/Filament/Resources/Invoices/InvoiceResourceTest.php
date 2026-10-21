<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = tagihanFixture();
});

/**
 * Tagihan Oktober 2026 dengan dua item (SPP dan Makan) untuk santri baru.
 *
 * @param  array<string, mixed>  $attributes
 */
function invoiceWithItems(object $f, array $attributes = []): Invoice
{
    $invoice = tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 1200000, 'due_on' => '2026-10-10', ...$attributes]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->spp->id, 'description' => 'SPP Oktober 2026', 'base_amount' => 500000, 'discount_amount' => 0, 'amount' => 500000, 'rate_source' => 'jenjang']);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->makan->id, 'description' => 'Makan Oktober 2026', 'base_amount' => 700000, 'discount_amount' => 0, 'amount' => 700000, 'rate_source' => 'jenjang']);

    return $invoice;
}

it('AC-46: scopes invoices to the units of the user', function () {
    $f = $this->f;
    $sltp = invoiceWithItems($f);
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year);
    $archivedStudent = tagihanStudent($f);
    $archived = tagihanInvoice($archivedStudent, $f->year, ['billing_group' => 'LAIN']);
    $archivedStudent->delete();
    $user = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$sltp, $archived])
        ->assertCanNotSeeTableRecords([$slta]);

    $this->get("/admin/invoices/{$sltp->id}")->assertOk();
    $this->get("/admin/invoices/{$slta->id}")->assertNotFound();
});

it('AC-46: shows every unit to a bendahara without a unit scope', function () {
    $f = $this->f;
    $sltp = invoiceWithItems($f);
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$sltp, $slta]);
});

it('AC-46: filters the list by status and billing group', function () {
    $f = $this->f;
    $this->travelTo('2026-10-15 09:00:00');
    $a = invoiceWithItems($f);
    $b = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'ASRAMA', 'status' => 'lunas', 'total' => 100000, 'paid_total' => 100000]);
    $c = tagihanInvoice(tagihanStudent($f), $f->year, ['period' => now()->addMonth()->startOfMonth()->toImmutable(), 'billing_group' => 'BULANAN']);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->filterTable('status', 'lunas')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a, $c])
        ->removeTableFilter('status')
        ->filterTable('billing_group', 'ASRAMA')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a, $c]);
});

it('AC-47: generates invoices from the panel', function () {
    $this->travelTo('2026-10-15 09:00:00');
    $f = $this->f;
    tagihanStudent($f, resident: true);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)
        ->callAction('generate', ['period' => '2026-10-01'])
        ->assertHasNoFormErrors();

    $expected = __('pondok.invoice.generated', ['period' => 'Oktober 2026', 'created' => 1, 'existing' => 0, 'skipped' => 0]);

    expect(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($user->id)
        ->and(invoiceDatabaseNotificationText($user))->toContain('success|')->toContain($expected);

    Livewire::test(ListInvoices::class)->callAction('generate', ['period' => '2026-10-01']);

    expect(Invoice::withoutUnitScope()->count())->toBe(1);
});

it('AC-47: shows an error on the period field when no academic year covers it', function () {
    $this->travelTo('2026-10-15 09:00:00');
    tagihanStudent($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction('generate', ['period' => '2030-01-01'])
        ->assertHasFormErrors(['period']);

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-47: mentions skipped students in the notification', function () {
    $this->travelTo('2026-10-15 09:00:00');
    $f = $this->f;
    tagihanStudent($f);
    tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)->callAction('generate', ['period' => '2026-10-01']);

    expect(invoiceDatabaseNotificationText($user))->toContain(__('pondok.invoice.generated', ['period' => 'Oktober 2026', 'created' => 1, 'existing' => 0, 'skipped' => 1]));
});

it('AC-48: hides generate without the generate permission', function () {
    $this->travelTo('2026-10-15 09:00:00');
    $user = User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']);
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)->assertActionHidden('generate');

    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)->assertActionVisible('generate');
});

it('AC-49: cancels an invoice from the table with a reason', function () {
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('cancel')->table($invoice), ['reason' => 'Salah tagih'])
        ->assertHasNoFormErrors();

    expect($invoice->fresh()->status)->toBe('batal')->and($invoice->items()->count())->toBe(2);
});

it('AC-49: requires a reason to cancel', function () {
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('cancel')->table($invoice), ['reason' => ''])
        ->assertHasFormErrors(['reason']);

    expect($invoice->fresh()->status)->toBe('belum');
});

it('AC-49: cancels from the detail page', function () {
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('cancel', ['reason' => 'Salah tagih'])
        ->assertHasNoFormErrors();

    expect($invoice->fresh()->status)->toBe('batal');
});

it('AC-49: hides cancel for cancelled and paid invoices', function () {
    $f = $this->f;
    $open = invoiceWithItems($f);
    $cancelled = tagihanInvoice(tagihanStudent($f), $f->year, ['status' => 'batal']);
    $paid = tagihanInvoice(tagihanStudent($f), $f->year, ['status' => 'lunas', 'total' => 500000, 'paid_total' => 500000]);
    $partial = tagihanInvoice(tagihanStudent($f), $f->year, ['status' => 'sebagian', 'total' => 500000, 'paid_total' => 100000]);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->assertActionVisible(TestAction::make('cancel')->table($open))
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled))
        ->assertActionHidden(TestAction::make('cancel')->table($paid))
        ->assertActionHidden(TestAction::make('cancel')->table($partial));

    Livewire::test(ViewInvoice::class, ['record' => $cancelled->id])->assertActionHidden('cancel');
});

it('AC-49: forbids cancelling without the cancel permission', function () {
    $invoice = invoiceWithItems($this->f);
    $user = User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']);
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)->assertActionHidden(TestAction::make('cancel')->table($invoice));
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('cancel');

    expect($invoice->fresh()->status)->toBe('belum');
});

it('AC-49: has no edit or delete actions', function () {
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ListInvoices::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($invoice))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($invoice))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('edit');

    $this->get("/admin/invoices/{$invoice->id}/edit")->assertNotFound();
    $this->get('/admin/invoices/create')->assertNotFound();
});

it('AC-50: formats money, period and status', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $page = Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    $text = tagihanPlainText($page);

    expect($text)->toContain('Rp 1.200.000')
        ->toContain('Oktober 2026')
        ->toContain(__('pondok.invoice.statuses.belum'))
        ->toContain('Belum bayar')
        ->toContain($invoice->invoice_no)
        ->toContain($invoice->student->nis)
        ->toContain($invoice->student->name)
        ->not->toContain('Lewat jatuh tempo');
});

it('AC-50: marks unpaid invoices past their due date', function () {
    $this->travelTo('2026-10-15 09:00:00');
    invoiceWithItems($this->f, ['due_on' => '2026-10-10']);
    $this->actingAs(keuanganUser('bendahara'));

    expect(tagihanPlainText(Livewire::test(ListInvoices::class)))->toContain('Lewat jatuh tempo');
});

it('AC-50: shows the empty state', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $page = Livewire::test(ListInvoices::class);

    expect(__('pondok.invoice.empty'))->not->toBe('pondok.invoice.empty')
        ->and(tagihanPlainText($page))->toContain(__('pondok.invoice.empty'));
});

it('AC-51: shows invoice items with the free label', function () {
    $f = $this->f;
    $invoice = tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 400000]);
    $override = FeeOverride::factory()->free()->create(['student_id' => $invoice->student_id, 'fee_type_id' => $f->makan->id]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->spp->id, 'description' => 'SPP Oktober 2026', 'base_amount' => 500000, 'discount_amount' => 100000, 'amount' => 400000, 'rate_source' => 'jenjang']);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->makan->id, 'description' => 'Makan Oktober 2026', 'base_amount' => 700000, 'discount_amount' => 700000, 'amount' => 0, 'rate_source' => 'jenjang', 'fee_override_id' => $override->id]);
    $this->actingAs(keuanganUser('bendahara'));

    $text = tagihanPlainText(Livewire::test(ViewInvoice::class, ['record' => $invoice->id]));

    expect($text)->toContain('SPP Oktober 2026')
        ->toContain('Makan Oktober 2026')
        ->toContain('Rp 500.000')
        ->toContain('Rp 100.000')
        ->toContain('Rp 400.000')
        ->toContain('Rp 700.000')
        ->toContain('Gratis')
        ->toContain($invoice->invoice_no)
        ->toContain('Oktober 2026');
});

it('AC-54: shows no raw language keys', function () {
    $this->travelTo('2026-10-15 09:00:00');
    $invoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $list = Livewire::test(ListInvoices::class);
    $detail = Livewire::test(ViewInvoice::class, ['record' => $invoice->id]);
    $generate = Livewire::test(ListInvoices::class)->mountAction('generate');
    $cancel = Livewire::test(ListInvoices::class)->mountAction(TestAction::make('cancel')->table($invoice));
    $empty = tagihanPlainText((function () {
        InvoiceItem::query()->delete();
        Invoice::query()->delete();

        return Livewire::test(ListInvoices::class);
    })());

    foreach ([tagihanPlainText($list), tagihanPlainText($detail), tagihanPlainText($generate), tagihanPlainText($cancel), $empty] as $text) {
        expect($text)->not->toContain('pondok.invoice.')->not->toContain('pondok.navigation.');
    }
});

/**
 * Tagihan pendaftar SLTP "PSB-2027-0001 — Ahmad Fauzan" (wali Budi Santoso) dan tagihan pendaftar SLTA.
 *
 * @return array{0: Invoice, 1: Invoice}
 */
function invoicesOfApplicants(object $f): array
{
    $sltp = Applicant::factory()->create(['unit_id' => $f->sltp->id, 'registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan', 'guardian_name' => 'Budi Santoso']);
    $slta = Applicant::factory()->create(['unit_id' => $f->slta->id, 'registration_no' => 'PSB-2027-0002', 'name' => 'Siti Aminah']);
    $make = fn (Applicant $applicant) => Invoice::factory()->create([
        'student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->year->id, 'billing_group' => 'PSB_DAFTAR', 'total' => 250000,
    ]);

    return [$make($sltp), $make($slta)];
}

it('AC-39: lists applicant invoices with the registration number and name', function () {
    [$applicantInvoice] = invoicesOfApplicants($this->f);
    $studentInvoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$applicantInvoice, $studentInvoice]);

    expect(tagihanPlainText($component))->toContain('PSB-2027-0001')->toContain('Ahmad Fauzan');
});

it('AC-39: finds applicant invoices by registration number and name', function () {
    [$applicantInvoice, $other] = invoicesOfApplicants($this->f);
    $studentInvoice = invoiceWithItems($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->searchTable('PSB-2027-0001')->assertCanSeeTableRecords([$applicantInvoice])->assertCanNotSeeTableRecords([$other, $studentInvoice])
        ->searchTable('Ahmad')->assertCanSeeTableRecords([$applicantInvoice])->assertCanNotSeeTableRecords([$other, $studentInvoice]);
});

it('AC-39: filters applicant invoices by unit', function () {
    [$sltp, $slta] = invoicesOfApplicants($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->filterTable('unit', $this->f->sltp->id)->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta])
        ->removeTableFilters()
        ->filterTable('unit', $this->f->slta->id)->assertCanSeeTableRecords([$slta])->assertCanNotSeeTableRecords([$sltp]);
});

it('AC-39: shows the applicant owner on the invoice detail', function () {
    [$invoice] = invoicesOfApplicants($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    expect(tagihanPlainText(Livewire::test(ViewInvoice::class, ['record' => $invoice->id])))->toContain('PSB-2027-0001 — Ahmad Fauzan');
});

<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = tagihanFixture();
    $this->sltpApplicant = Applicant::factory()->create(['unit_id' => $this->f->sltp->id]);
    $this->sltaApplicant = Applicant::factory()->create(['unit_id' => $this->f->slta->id]);
    $this->deletedApplicant = Applicant::factory()->create(['unit_id' => $this->f->sltp->id]);
    $make = fn (Applicant $applicant, string $group) => Invoice::factory()->create([
        'student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $this->f->year->id, 'billing_group' => $group, 'total' => 250000,
    ]);
    $this->sltpInvoice = $make($this->sltpApplicant, 'PSB_DAFTAR');
    $this->sltaInvoice = $make($this->sltaApplicant, 'PSB_DAFTAR');
    $this->deletedInvoice = $make($this->deletedApplicant, 'PSB_DAFTAR');
    $this->deletedApplicant->delete();
    $this->studentSltp = tagihanInvoice(tagihanStudent($this->f), $this->f->year);
    $this->studentSlta = tagihanInvoice(tagihanStudent($this->f, cohort: $this->f->cohortSlta, attributes: ['unit_id' => $this->f->slta->id]), $this->f->year);
    $this->sltpPayment = Payment::factory()->create(['invoice_id' => $this->sltpInvoice->id, 'amount' => 250000]);
    $this->sltaPayment = Payment::factory()->create(['invoice_id' => $this->sltaInvoice->id, 'amount' => 250000]);
});

it('AC-38: shows a scoped user the applicant invoices of the own unit', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $visible = Invoice::query()->pluck('id')->all();

    expect($visible)->toContain($this->sltpInvoice->id, $this->deletedInvoice->id, $this->studentSltp->id)
        ->not->toContain($this->sltaInvoice->id, $this->studentSlta->id);
});

it('AC-38: shows a scoped user the payments of the own unit applicant invoices', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $visible = Payment::query()->pluck('id')->all();

    expect($visible)->toContain($this->sltpPayment->id)->not->toContain($this->sltaPayment->id);
});

it('AC-38: lists the same in the invoice and payment tables', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$this->sltpInvoice, $this->studentSltp])
        ->assertCanNotSeeTableRecords([$this->sltaInvoice, $this->studentSlta]);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$this->sltpPayment])
        ->assertCanNotSeeTableRecords([$this->sltaPayment]);
});

it('AC-38: shows everything to an unscoped treasurer and without a login', function () {
    $everything = [$this->sltpInvoice->id, $this->sltaInvoice->id, $this->deletedInvoice->id, $this->studentSltp->id, $this->studentSlta->id];

    expect(Invoice::query()->pluck('id')->all())->toContain(...$everything);

    $this->actingAs(keuanganUser('bendahara'));

    expect(Invoice::query()->pluck('id')->all())->toContain(...$everything)
        ->and(Payment::query()->pluck('id')->all())->toContain($this->sltpPayment->id, $this->sltaPayment->id);
});

it('AC-38: keeps hiding other units student invoices from a scoped user', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTA']));

    $visible = Invoice::query()->pluck('id')->all();

    expect($visible)->toContain($this->studentSlta->id, $this->sltaInvoice->id)
        ->not->toContain($this->studentSltp->id, $this->sltpInvoice->id, $this->deletedInvoice->id);
});

it('AC-38: blocks the invoice detail page of another unit applicant', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $this->get("/admin/invoices/{$this->sltpInvoice->id}")->assertOk();
    $this->get("/admin/invoices/{$this->sltaInvoice->id}")->assertNotFound();
});

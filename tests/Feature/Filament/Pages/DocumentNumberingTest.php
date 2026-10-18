<?php

use App\Actions\Akademik\GenerateStudentNumber;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateReceiptNumber;
use App\Filament\Pages\Settings\FinanceNumbering;
use App\Filament\Pages\Settings\StudentNumbering;
use App\Models\Setting;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
});

it('F039: lets admin-sekolah open the NIS page and forbids bendahara', function () {
    $this->actingAs(kontenUser('admin-sekolah'))->get('/admin/settings/student-numbering')->assertOk();
    $this->actingAs(kontenUser('bendahara'))->get('/admin/settings/student-numbering')->assertForbidden();
});

it('F039: lets bendahara open the finance page and forbids admin-sekolah', function () {
    $this->actingAs(kontenUser('bendahara'))->get('/admin/settings/finance-numbering')->assertOk();
    $this->actingAs(kontenUser('admin-sekolah'))->get('/admin/settings/finance-numbering')->assertForbidden();
});

it('F039: saves the NIS format', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(StudentNumbering::class)
        ->fillForm(['nis_format' => '{UNIT}-{YY}-{NNN}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get(GenerateStudentNumber::SETTING_KEY))->toBe('{UNIT}-{YY}-{NNN}');
});

it('F039: rejects an invalid NIS format', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(StudentNumbering::class)
        ->fillForm(['nis_format' => '{YYYY}'])
        ->call('save')
        ->assertHasFormErrors(['nis_format']);

    expect(Setting::get(GenerateStudentNumber::SETTING_KEY))->toBe(GenerateStudentNumber::DEFAULT_FORMAT);
});

it('F039: previews a number without a unit token', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    $page = Livewire::test(StudentNumbering::class)->instance();

    expect($page->previewFor('{YYYY}-{NNN}'))->toBe('2026-001')
        ->and($page->previewFor('{FOO}'))->toBe(__('pondok.numbering.invalid_format'));
});

it('F039: saves the invoice and receipt formats', function () {
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)
        ->fillForm(['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KT/{YY}/{NNNN}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get(GenerateInvoiceNumber::SETTING_KEY))->toBe('TGH/{YY}/{NNNN}')
        ->and(Setting::get(GenerateReceiptNumber::SETTING_KEY))->toBe('KT/{YY}/{NNNN}');
});

it('F039: shows the invoice and receipt previews', function () {
    $this->actingAs(kontenUser('bendahara'));

    $page = Livewire::test(FinanceNumbering::class)->instance();

    expect($page->invoicePreview('INV-{YYYY}{MM}-{NNNNN}'))->toBe('INV-202610-00001')
        ->and($page->receiptPreview('KW-{YYYY}{MM}-{NNNNN}'))->toBe('KW-202610-00001');
});

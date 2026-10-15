<?php

use App\Actions\Keuangan\GenerateInvoiceNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->f = tagihanFixture(withRates: false);
});

it('AC-11: formats numbers as INV-YYYYMM-NNNNN', function () {
    $action = app(GenerateInvoiceNumber::class);
    $month = CarbonImmutable::parse('2026-10-17');

    expect($action->format($month, 1))->toBe('INV-202610-00001')
        ->and($action->format($month, 99999))->toBe('INV-202610-99999')
        ->and($action->format(CarbonImmutable::parse('2026-11-01'), 12))->toBe('INV-202611-00012');
});

it('AC-11: refuses sequences above 99999', function () {
    expect(fn () => app(GenerateInvoiceNumber::class)->format(CarbonImmutable::parse('2026-10-01'), 100000))
        ->toThrow(LogicException::class);
});

it('AC-11: starts at 1 and continues from the highest number of the month', function () {
    $f = $this->f;
    $action = app(GenerateInvoiceNumber::class);
    $month = CarbonImmutable::parse('2026-10-01');

    expect($action->lastSequence($month))->toBe(0)
        ->and($action->handle($month))->toBe('INV-202610-00001');

    tagihanInvoice(tagihanStudent($f), $f->year, ['invoice_no' => 'INV-202610-00007']);
    tagihanInvoice(tagihanStudent($f), $f->year, ['invoice_no' => 'INV-202609-00099', 'period' => CarbonImmutable::parse('2026-09-01')->startOfMonth()]);

    expect($action->lastSequence($month))->toBe(7)
        ->and($action->handle($month))->toBe('INV-202610-00008')
        ->and($action->handle(CarbonImmutable::parse('2026-11-01')))->toBe('INV-202611-00001');
});

it('AC-59: counts cancelled invoices and invoices of other units for a scoped user', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    $slta = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    tagihanInvoice($slta, $f->year, ['billing_group' => 'X', 'invoice_no' => 'INV-202610-00005']);
    tagihanInvoice($slta, $f->year, ['billing_group' => 'X', 'invoice_no' => 'INV-202610-00006', 'status' => 'batal']);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(app(GenerateInvoiceNumber::class)->lastSequence(CarbonImmutable::parse('2026-10-01')))->toBe(6);
});

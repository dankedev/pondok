<?php

use App\Actions\Keuangan\ImportOpeningBalanceRow;
use App\Actions\Keuangan\ListTopArrears;
use App\Actions\Keuangan\SaveFeeType;
use App\Actions\Keuangan\SummarizeInvoices;
use App\Models\FeeType;
use App\Models\Invoice;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->s = saldoFixture(withRates: true);
    $this->actingAs(keuanganUser('bendahara'));
});

it('AC-9: the monthly generator still bills SPP of the import month and leaves the opening invoice alone', function () {
    $opening = app(ImportOpeningBalanceRow::class)->handle(saldoRow(['amount' => '700000']));

    $result = tagihanGenerate('2026-10-01');

    $monthly = tagihanInvoiceFor($this->s->student);

    expect($result['created_invoices'])->toBe(1)
        ->and($monthly)->not->toBeNull()
        ->and($monthly->id)->not->toBe($opening->id)
        ->and($monthly->billing_group)->toBe('BULANAN')
        ->and($monthly->total)->toBe(500000)
        ->and($opening->fresh())->total->toBe(700000)->billing_group->toBe('SALDO_AWAL')->status->toBe('belum')
        ->and($opening->items()->count())->toBe(1);

    $again = tagihanGenerate('2026-10-01');

    expect($again['created_invoices'])->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(2)
        ->and($monthly->items()->count())->toBe(1);
});

it('AC-10: SaveFeeType reserves the SALDO_AWAL billing group in any spelling, for create and update', function () {
    $existing = app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'LAIN', 'billing_group' => 'LAIN']));

    foreach (['SALDO_AWAL', 'saldo_awal', ' saldo awal '] as $group) {
        keuanganAssertRejected(fn () => app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'BARU', 'billing_group' => $group])), 'billing_group_reserved', 'billing_group');
        keuanganAssertRejected(fn () => app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'LAIN', 'billing_group' => $group]), $existing), 'billing_group_reserved', 'billing_group');
    }

    expect(FeeType::query()->where('code', 'BARU')->exists())->toBeFalse()
        ->and($existing->fresh()->billing_group)->toBe('LAIN')
        ->and(app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'SALDO', 'billing_group' => 'SALDO_AKHIR']))->billing_group)->toBe('SALDO_AKHIR');
});

it('AC-11: shows the opening balance in arrears and the period summary, separable by group', function () {
    app(ImportOpeningBalanceRow::class)->handle(saldoRow(['amount' => '700000']));

    $arrears = app(ListTopArrears::class)->handle(laporanFilter('2026-10'));

    expect($arrears)->toHaveCount(1)
        ->and($arrears[0]['student_id'])->toBe($this->s->student->id)
        ->and($arrears[0]['overdue_amount'])->toBe(700000)
        ->and($arrears[0]['oldest_period'])->toBe('2026-10');

    $summary = app(SummarizeInvoices::class)->handle(laporanFilter('2026-10'));
    $onlyOpening = app(SummarizeInvoices::class)->handle(laporanFilter('2026-10', group: 'SALDO_AWAL'));
    $otherGroup = app(SummarizeInvoices::class)->handle(laporanFilter('2026-10', group: 'BULANAN'));

    expect($summary['totals'])->toMatchArray(['billed' => 700000, 'paid' => 0, 'overdue' => 700000])
        ->and($onlyOpening['totals'])->toMatchArray(['billed' => 700000, 'invoice_count' => 1])
        ->and($otherGroup['totals']['billed'])->toBe(0);
});

it('AC-11: payments through RecordPayment move the opening invoice to sebagian then lunas and allocate to the item', function () {
    $invoice = app(ImportOpeningBalanceRow::class)->handle(saldoRow(['amount' => '700000']));

    bayarRecord($invoice, 300000);

    $item = $invoice->items()->sole();

    expect($invoice->fresh())->paid_total->toBe(300000)->status->toBe('sebagian')
        ->and($item->fresh()->paid_amount)->toBe(300000);

    bayarRecord($invoice->fresh(), 400000);

    expect($invoice->fresh())->paid_total->toBe(700000)->status->toBe('lunas')
        ->and($item->fresh()->paid_amount)->toBe(700000);
});

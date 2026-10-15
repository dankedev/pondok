<?php

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\SummarizeCashReceipts;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

function laporanCash(string $from = '2026-10', ?string $to = null, ?int $unitId = null, ?string $group = null): array
{
    return app(SummarizeCashReceipts::class)->handle(laporanFilter($from, $to, $unitId, $group));
}

it('AC-13: counts received payments by paid date', function () {
    $f = $this->f;
    $invoice = laporanInvoice($f, laporanStudent($f), '2026-10-01', 5000000);
    laporanPayment($invoice, 200000, '2026-10-01');
    laporanPayment($invoice, 300000, '2026-10-31');
    laporanPayment($invoice, 50000, '2026-09-30');
    laporanPayment($invoice, 60000, '2026-11-01');

    $cancellable = laporanInvoiceWithItems($f, laporanStudent($f), ['SPP' => 500000]);
    $payment = bayarRecord($cancellable, 100000, ['paid_on' => '2026-10-15']);
    app(CancelPayment::class)->handle($payment, User::factory()->create(), 'Salah input');

    $cash = laporanCash();

    expect($cash['total'])->toBe(500000)
        ->and($cash['payment_count'])->toBe(2)
        ->and($cash['total'])->toBeInt();
});

it('AC-13: uses the paid date rather than the recorded date', function () {
    $f = $this->f;
    $invoice = laporanInvoice($f, laporanStudent($f), '2026-10-01', 5000000);
    laporanPayment($invoice, 200000, '2026-10-01');
    laporanPayment($invoice, 100000, '2026-10-10', 'tunai', ['created_at' => '2026-11-02 09:00:00']);

    expect(laporanCash()['total'])->toBe(300000)
        ->and(laporanCash()['payment_count'])->toBe(2)
        ->and(laporanCash('2026-11')['total'])->toBe(0);
});

it('AC-13: reports zero cash without payments', function () {
    $cash = laporanCash();

    expect($cash['total'])->toBe(0)
        ->and($cash['payment_count'])->toBe(0)
        ->and($cash['by_day'])->toBe([])
        ->and($cash['by_fee_type'])->toBe([])
        ->and($cash['by_method'])->toHaveKeys(['tunai', 'transfer', 'gateway']);
});

it('AC-14: breaks cash down by method, day, and fee type', function () {
    laporanCashFixture($this->f);

    $cash = laporanCash();

    expect($cash['total'])->toBe(820000)
        ->and($cash['payment_count'])->toBe(3)
        ->and($cash['by_method']['tunai'])->toEqual(['count' => 2, 'amount' => 670000])
        ->and($cash['by_method']['transfer'])->toEqual(['count' => 1, 'amount' => 150000])
        ->and($cash['by_method']['gateway'])->toEqual(['count' => 0, 'amount' => 0])
        ->and(array_map(fn (array $row): array => [$row['date'], $row['count'], $row['amount']], $cash['by_day']))->toBe([
            ['2026-10-14', 1, 150000],
            ['2026-10-15', 2, 670000],
        ])
        ->and(array_map(fn (array $row): array => [$row['code'], $row['name'], $row['amount']], $cash['by_fee_type']))->toBe([
            ['MAKAN', 'Makan', 400000],
            ['ASRAMA', 'Asrama', 200000],
            ['SPP', 'SPP', 220000],
        ])
        ->and(array_sum(array_column($cash['by_fee_type'], 'amount')))->toBe($cash['total'])
        ->and($cash['by_fee_type'][0]['fee_type_id'])->toBe($this->f->makan->id);
});

it('AC-14: limits cash by unit and billing group through the invoice', function () {
    $f = $this->f;
    laporanCashFixture($f);

    $sltp = laporanCash(unitId: $f->sltp->id);
    $group = laporanCash(group: 'LAIN');

    expect($sltp['total'])->toBe(750000)
        ->and($sltp['by_method']['tunai']['amount'])->toBe(600000)
        ->and(array_sum(array_column($sltp['by_fee_type'], 'amount')))->toBe(750000)
        ->and($group['total'])->toBe(150000)
        ->and($group['payment_count'])->toBe(1)
        ->and(array_map(fn (array $row): string => $row['code'], $group['by_fee_type']))->toBe(['SPP']);
});

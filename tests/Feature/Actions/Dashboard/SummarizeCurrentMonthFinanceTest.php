<?php

use App\Actions\Dashboard\SummarizeCurrentMonthFinance;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = dashboardBoot();
});

function dashboardFinance(?CarbonImmutable $asOf = null): array
{
    return app(SummarizeCurrentMonthFinance::class)->handle($asOf);
}

it('AC-7: summarizes the current month', function () {
    dashboardFinanceData($this->f);

    $summary = dashboardFinance();

    expect($summary)->toMatchArray([
        'period' => '2026-10',
        'invoice_count' => 3,
        'billed' => 1000000,
        'paid' => 600000,
        'collection_rate' => 60,
        'overdue' => 200000,
        'overdue_student_count' => 1,
        'cash_received' => 600000,
        'payment_count' => 2,
    ])->and(array_keys($summary))->toHaveCount(9);
});

it('AC-8: returns zeros without data', function () {
    $summary = dashboardFinance();

    expect($summary)->toMatchArray([
        'period' => '2026-10',
        'invoice_count' => 0,
        'billed' => 0,
        'paid' => 0,
        'collection_rate' => null,
        'overdue' => 0,
        'overdue_student_count' => 0,
        'cash_received' => 0,
        'payment_count' => 0,
    ]);
});

it('AC-7: does not count an invoice due today as overdue but counts one due yesterday', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 0, null, '2026-10-15');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 70000, 20000, null, '2026-10-14');

    expect(dashboardFinance())->toMatchArray(['overdue' => 50000, 'overdue_student_count' => 1, 'invoice_count' => 2]);
});

it('AC-7: passes the as-of date to the overdue calculation', function () {
    dashboardFinanceData($this->f);

    expect(dashboardFinance(CarbonImmutable::parse('2026-10-25', 'Asia/Jakarta')))
        ->toMatchArray(['overdue' => 400000, 'overdue_student_count' => 2]);
});

it('AC-7: excludes invoices and payments on the neighbouring months', function () {
    $f = $this->f;
    $october = laporanInvoice($f, laporanStudent($f), '2026-10-01', 400000, 300000);
    $november = laporanInvoice($f, laporanStudent($f), '2026-11-01', 900000, 900000);

    laporanPayment($october, 100000, '2026-10-01');
    laporanPayment($october, 200000, '2026-10-31');
    laporanPayment($october, 33000, '2026-09-30');
    laporanPayment($november, 77000, '2026-11-01');

    expect(dashboardFinance())->toMatchArray([
        'invoice_count' => 1,
        'billed' => 400000,
        'paid' => 300000,
        'cash_received' => 300000,
        'payment_count' => 2,
    ]);
});

it('AC-7: follows the Asia/Jakarta month at the UTC month boundary', function () {
    $f = $this->f;
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00', 'UTC'));

    $october = laporanInvoice($f, laporanStudent($f), '2026-10-01', 400000, 100000);
    $november = laporanInvoice($f, laporanStudent($f), '2026-11-01', 250000, 50000);
    laporanPayment($october, 100000, '2026-10-31');
    laporanPayment($november, 50000, '2026-11-01');

    expect(dashboardFinance())->toMatchArray([
        'period' => '2026-11',
        'invoice_count' => 1,
        'billed' => 250000,
        'paid' => 50000,
        'cash_received' => 50000,
        'payment_count' => 1,
    ]);
});

it('AC-9: respects the unit scope', function () {
    dashboardFinanceData($this->f, 'slta');

    $unscoped = dashboardFinance();
    expect($unscoped['billed'])->toBe(1000000);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(dashboardFinance())->toMatchArray([
        'invoice_count' => 2,
        'billed' => 800000,
        'paid' => 600000,
        'overdue' => 200000,
        'overdue_student_count' => 1,
        'cash_received' => 600000,
        'payment_count' => 2,
    ]);
});

it('AC-9: leaves payments of another unit out of the cash received', function () {
    $f = $this->f;
    dashboardFinanceData($f);
    $slta = laporanInvoice($f, laporanStudent($f, 'slta'), '2026-10-01', 250000, 250000);
    laporanPayment($slta, 250000, '2026-10-08');

    expect(dashboardFinance())->toMatchArray(['cash_received' => 850000, 'payment_count' => 3]);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(dashboardFinance())->toMatchArray(['cash_received' => 600000, 'payment_count' => 2, 'billed' => 1000000]);
});

it('AC-9: shows every unit to a bendahara without unit rows', function () {
    dashboardFinanceData($this->f, 'slta');
    $this->actingAs(keuanganUser('bendahara'));

    expect(dashboardFinance()['billed'])->toBe(1000000);
});

it('AC-7: does not write activity logs or change data', function () {
    dashboardFinanceData($this->f);
    $before = [Invoice::withoutUnitScope()->sum('paid_total'), Activity::query()->count()];

    dashboardFinance();

    expect([Invoice::withoutUnitScope()->sum('paid_total'), Activity::query()->count()])->toBe($before);
});

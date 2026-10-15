<?php

use App\Actions\Keuangan\FinanceReportFilter;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, UnitSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
});

it('AC-1: defaults to the current month', function () {
    $filter = FinanceReportFilter::currentMonth();

    expect($filter->periodFrom->toDateString())->toBe('2026-10-01')
        ->and($filter->periodTo->toDateString())->toBe('2026-10-01')
        ->and($filter->unitId)->toBeNull()
        ->and($filter->billingGroup)->toBeNull()
        ->and(array_map(fn ($month): string => $month->toDateString(), $filter->months()))->toBe(['2026-10-01'])
        ->and($filter->periodEndExclusive()->toDateString())->toBe('2026-11-01');
});

it('AC-1: lists every month in a range and exports a plain array', function () {
    $filter = FinanceReportFilter::make('2026-08', '2026-10');

    expect(array_map(fn ($month): string => $month->toDateString(), $filter->months()))->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and($filter->toArray())->toBe(['period_from' => '2026-08', 'period_to' => '2026-10', 'unit_id' => null, 'billing_group' => null]);
});

it('AC-2: rejects invalid report filters', function (mixed $from, mixed $to, string $field, string $reason) {
    laporanAssertRejected(fn () => FinanceReportFilter::make($from, $to), $reason, $field);
})->with([
    'bulan 13' => ['2026-13', '2026-10', 'period_from', 'period_invalid'],
    'bukan tanggal' => ['abc', '2026-10', 'period_from', 'period_invalid'],
    'null' => [null, '2026-10', 'period_from', 'period_invalid'],
    'akhir tidak valid' => ['2026-10', 'xx', 'period_to', 'period_invalid'],
    'urutan terbalik' => ['2026-10', '2026-09', 'period_to', 'period_order'],
    '13 bulan' => ['2025-10', '2026-10', 'period_to', 'period_span'],
]);

it('AC-2: accepts a range of exactly 12 months and Carbon instances', function () {
    $twelve = FinanceReportFilter::make('2025-11', '2026-10');
    $carbon = FinanceReportFilter::make(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-10-03'));

    expect($twelve->months())->toHaveCount(12)
        ->and($carbon->toArray()['period_from'])->toBe('2026-09')
        ->and($carbon->toArray()['period_to'])->toBe('2026-10');
});

it('AC-2: rejects an unknown unit', function () {
    laporanAssertRejected(fn () => FinanceReportFilter::make('2026-10', '2026-10', 999999), 'unit_invalid', 'unit_id');
});

it('AC-2: rejects a unit outside the scope of the logged-in user', function () {
    $slta = Unit::query()->where('code', 'SLTA')->firstOrFail();
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    laporanAssertRejected(fn () => FinanceReportFilter::make('2026-10', '2026-10', $slta->id), 'unit_invalid', 'unit_id');

    expect(FinanceReportFilter::make('2026-10', '2026-10', $sltp->id)->unitId)->toBe($sltp->id);
});

it('AC-2: rejects a billing group longer than 30 characters', function () {
    laporanAssertRejected(fn () => FinanceReportFilter::make('2026-10', '2026-10', null, str_repeat('A', 31)), 'billing_group_invalid', 'billing_group');

    expect(FinanceReportFilter::make('2026-10', '2026-10', null, str_repeat('A', 30))->billingGroup)->toBe(str_repeat('A', 30));
});

it('AC-2: turns empty unit and billing group into null and trims the group', function () {
    $empty = FinanceReportFilter::make('2026-10', '2026-10', '', '');
    $trimmed = FinanceReportFilter::make('2026-10', '2026-10', null, '  ASRAMA ');

    expect($empty->unitId)->toBeNull()
        ->and($empty->billingGroup)->toBeNull()
        ->and($trimmed->billingGroup)->toBe('ASRAMA');
});

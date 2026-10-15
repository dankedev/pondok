<?php

use App\Actions\Keuangan\ListTopArrears;
use App\Models\Applicant;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

function laporanArrearsRows(array $rows): array
{
    return array_map(fn (array $row): array => [$row['name'], $row['overdue_amount'], $row['overdue_invoice_count'], $row['oldest_period']], $rows);
}

it('AC-9: ranks students by overdue amount', function () {
    $f = $this->f;
    $a = laporanStudent($f, attributes: ['nis' => '2000000001', 'name' => 'Ahmad']);
    $b = laporanStudent($f, attributes: ['name' => 'Budi']);
    $c = laporanStudent($f, attributes: ['name' => 'Candra']);
    $d = laporanStudent($f, attributes: ['name' => 'Dani']);
    $e = laporanStudent($f, attributes: ['name' => 'Eko']);
    $g = laporanStudent($f, attributes: ['name' => 'Fajar']);

    laporanInvoice($f, $a, '2026-08-01', 400000, 0, null, '2026-08-10');
    laporanInvoice($f, $a, '2026-10-01', 500000, 0, null, '2026-10-10', 'ASRAMA');
    laporanInvoice($f, $b, '2026-10-01', 1200000, 0, null, '2026-10-10');
    laporanInvoice($f, $c, '2026-10-01', 900000, 0, null, '2026-10-10');
    laporanInvoice($f, $d, '2026-10-01', 900000, 0, null, '2026-10-20');
    laporanInvoice($f, $e, '2026-10-01', 900000, 0, Invoice::STATUS_CANCELLED, '2026-10-10');
    laporanInvoice($f, $g, '2026-10-01', 900000, 900000, null, '2026-10-10');

    $rows = app(ListTopArrears::class)->handle(laporanFilter('2026-10'));

    expect(laporanArrearsRows($rows))->toBe([
        ['Budi', 1200000, 1, '2026-10'],
        ['Ahmad', 900000, 2, '2026-08'],
        ['Candra', 900000, 1, '2026-10'],
    ]);

    expect($rows[1]['student_id'])->toBe($a->id)
        ->and($rows[1]['nis'])->toBe('2000000001')
        ->and($rows[1]['unit_name'])->toBe($f->sltp->name)
        ->and($rows[1]['is_archived'])->toBeFalse()
        ->and($rows[1]['overdue_amount'])->toBeInt();
});

it('AC-9: applies unit and billing group filters but ignores the period range', function () {
    $f = $this->f;
    $a = laporanStudent($f, attributes: ['name' => 'Ahmad']);
    $slta = laporanStudent($f, 'slta', ['name' => 'Gani']);
    laporanInvoice($f, $a, '2026-08-01', 400000, 0, null, '2026-08-10');
    laporanInvoice($f, $a, '2026-10-01', 500000, 0, null, '2026-10-10', 'ASRAMA');
    laporanInvoice($f, $slta, '2026-10-01', 100000, 0, null, '2026-10-10');

    $action = app(ListTopArrears::class);

    expect(laporanArrearsRows($action->handle(laporanFilter('2026-10', unitId: $f->sltp->id))))->toBe([['Ahmad', 900000, 2, '2026-08']])
        ->and(laporanArrearsRows($action->handle(laporanFilter('2026-10', group: 'ASRAMA'))))->toBe([['Ahmad', 500000, 1, '2026-10']])
        ->and(laporanArrearsRows($action->handle(laporanFilter('2026-10', unitId: $f->slta->id))))->toBe([['Gani', 100000, 1, '2026-10']]);
});

it('AC-10: limits rows and keeps archived students', function () {
    $f = $this->f;

    foreach (range(1, 12) as $i) {
        $student = laporanStudent($f, attributes: ['name' => "Santri {$i}"]);
        laporanInvoice($f, $student, '2026-10-01', $i * 100000, 0, null, '2026-10-10');

        if ($i === 1) {
            $student->delete();
        }

        if ($i === 2) {
            $student->update(['status' => 'lulus']);
        }
    }

    laporanInvoice($f, laporanStudent($f), '2026-10-01', 9900000, 0, null, '2026-10-10', 'BULANAN', ['student_id' => null, 'applicant_id' => Applicant::factory()->create(['unit_id' => $f->slta->id])->id]);

    $action = app(ListTopArrears::class);
    $filter = laporanFilter('2026-10');
    $all = $action->handle($filter, null);
    $byName = collect($all)->keyBy('name');

    expect($action->handle($filter))->toHaveCount(10)
        ->and($all)->toHaveCount(12)
        ->and($byName['Santri 1']['is_archived'])->toBeTrue()
        ->and($byName['Santri 2']['is_archived'])->toBeFalse()
        ->and($all[0]['name'])->toBe('Santri 12')
        ->and(collect($all)->pluck('overdue_amount')->max())->toBe(1200000);

    expect(fn () => $action->handle($filter, 0))->toThrow(InvalidArgumentException::class);
});

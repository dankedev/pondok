<?php

use App\Actions\Keuangan\SummarizeInvoices;
use App\Models\Applicant;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

function laporanSummary(?string $from = '2026-10', ?string $to = null, ?int $unitId = null, ?string $group = null, ?CarbonImmutable $asOf = null): array
{
    return app(SummarizeInvoices::class)->handle(laporanFilter($from, $to, $unitId, $group), $asOf);
}

it('AC-3: sums billed and paid for active invoices in range', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 600000, 200000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 400000, 400000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 999000, 0, Invoice::STATUS_CANCELLED);
    laporanInvoice($f, laporanStudent($f), '2026-09-01', 300000);
    laporanInvoice($f, laporanStudent($f), '2026-11-01', 700000);

    $totals = laporanSummary()['totals'];

    expect($totals['invoice_count'])->toBe(3)
        ->and($totals['billed'])->toBe(1500000)
        ->and($totals['paid'])->toBe(600000)
        ->and($totals['outstanding'])->toBe(900000);

    foreach (['invoice_count', 'billed', 'paid', 'outstanding', 'overdue', 'not_yet_due', 'overdue_invoice_count', 'overdue_student_count'] as $key) {
        expect($totals[$key])->toBeInt();
    }
});

it('AC-4: floors the collection rate', function (int $billed, int $paid, int $rate) {
    laporanInvoice($this->f, laporanStudent($this->f), '2026-10-01', $billed, $paid);

    expect(laporanSummary()['totals']['collection_rate'])->toBe($rate);
})->with([
    'dua pertiga' => [300000, 200000, 66],
    'hampir penuh' => [1000, 999, 99],
    'penuh' => [500000, 500000, 100],
    'nol' => [500000, 0, 0],
]);

it('AC-4: has no collection rate without invoices', function () {
    $summary = laporanSummary();

    expect($summary['totals']['invoice_count'])->toBe(0)
        ->and($summary['totals']['billed'])->toBe(0)
        ->and($summary['totals']['collection_rate'])->toBeNull();
});

it('AC-4: counts a zero-rupiah paid invoice without a collection rate', function () {
    laporanInvoice($this->f, laporanStudent($this->f), '2026-10-01', 0, 0, Invoice::STATUS_PAID);

    $totals = laporanSummary()['totals'];

    expect($totals['invoice_count'])->toBe(1)
        ->and($totals['billed'])->toBe(0)
        ->and($totals['collection_rate'])->toBeNull();
});

it('AC-5: counts overdue invoices before the as-of date', function () {
    $f = $this->f;
    $first = laporanStudent($f);
    laporanInvoice($f, $first, '2026-10-01', 500000, 0, null, '2026-10-14');
    laporanInvoice($f, $first, '2026-10-01', 600000, 200000, null, '2026-10-10', 'ASRAMA');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 0, null, '2026-10-15');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 200000, 0, null, '2026-10-20');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 300000, 300000, null, '2026-10-01');

    $summary = laporanSummary();
    $totals = $summary['totals'];

    expect($summary['as_of'])->toBe('2026-10-15')
        ->and($totals['outstanding'])->toBe(1200000)
        ->and($totals['overdue'])->toBe(900000)
        ->and($totals['overdue_invoice_count'])->toBe(2)
        ->and($totals['overdue_student_count'])->toBe(1)
        ->and($totals['not_yet_due'])->toBe(300000);
});

it('AC-5: moves the overdue boundary with an explicit as-of date', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 0, null, '2026-10-15');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 200000, 0, null, '2026-10-20');
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 300000, 0, null, '2026-10-21');

    $totals = laporanSummary(asOf: CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta'))['totals'];

    expect($totals['overdue'])->toBe(300000)
        ->and($totals['overdue_invoice_count'])->toBe(2)
        ->and($totals['not_yet_due'])->toBe(300000);
});

it('AC-6: breaks totals down by status', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 600000, 200000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 999000, 0, Invoice::STATUS_CANCELLED);

    $byStatus = laporanSummary()['totals']['by_status'];

    expect($byStatus)->toHaveKeys(['belum', 'sebagian', 'lunas'])
        ->and($byStatus)->not->toHaveKey('batal')
        ->and($byStatus['belum'])->toEqual(['count' => 1, 'total' => 500000, 'paid' => 0])
        ->and($byStatus['sebagian'])->toEqual(['count' => 1, 'total' => 600000, 'paid' => 200000])
        ->and($byStatus['lunas'])->toEqual(['count' => 0, 'total' => 0, 'paid' => 0]);
});

it('AC-7: returns every month in range', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-08-01', 300000, 100000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000, 500000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 200000);

    $summary = laporanSummary('2026-08', '2026-10');
    $periods = collect($summary['periods']);
    $september = $periods->firstWhere('period', '2026-09');

    expect($periods->pluck('period')->all())->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($september['invoice_count'])->toBe(0)
        ->and($september['billed'])->toBe(0)
        ->and($september['collection_rate'])->toBeNull()
        ->and($periods->firstWhere('period', '2026-08')['billed'])->toBe(300000)
        ->and($periods->firstWhere('period', '2026-10')['billed'])->toBe(700000)
        ->and($periods->firstWhere('period', '2026-10')['paid'])->toBe(500000)
        ->and($periods->sum('billed'))->toBe($summary['totals']['billed'])
        ->and($periods->sum('paid'))->toBe($summary['totals']['paid'])
        ->and($periods->sum('invoice_count'))->toBe($summary['totals']['invoice_count']);
});

it('AC-8: applies unit and billing group filters', function () {
    $f = $this->f;
    $sltp = laporanStudent($f);
    $archived = laporanStudent($f);
    $slta = laporanStudent($f, 'slta');
    laporanInvoice($f, $sltp, '2026-10-01', 100000);
    laporanInvoice($f, $sltp, '2026-10-01', 40000, 0, null, null, 'ASRAMA');
    laporanInvoice($f, $archived, '2026-10-01', 200000);
    laporanInvoice($f, $slta, '2026-10-01', 400000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 800000, 0, null, null, 'BULANAN', ['student_id' => null, 'applicant_id' => Applicant::factory()->create(['unit_id' => $f->slta->id])->id]);
    $archived->delete();

    expect(laporanSummary()['totals']['billed'])->toBe(1540000)
        ->and(laporanSummary(unitId: $f->sltp->id)['totals']['billed'])->toBe(340000)
        ->and(laporanSummary(unitId: $f->slta->id)['totals']['billed'])->toBe(400000)
        ->and(laporanSummary(group: 'ASRAMA')['totals']['billed'])->toBe(40000)
        ->and(laporanSummary(unitId: $f->sltp->id, group: 'ASRAMA')['totals']['invoice_count'])->toBe(1)
        ->and(laporanSummary(group: 'TIDAK-ADA')['totals']['invoice_count'])->toBe(0);
});

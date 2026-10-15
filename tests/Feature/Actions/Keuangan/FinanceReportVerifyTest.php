<?php

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Actions\Keuangan\SummarizeInvoices;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

it('VERIFY: neutralises formula characters in every text cell of the CSV', function (string $dangerous) {
    $f = $this->f;
    $student = laporanStudent($f);
    $invoice = laporanInvoice($f, $student, '2026-10-01', 400000);
    laporanItem($invoice, $f->spp, 500000, 100000, laporanOverride($student, $f->spp, laporanScholarship($dangerous, $dangerous)));

    $rows = app(ExportFinanceReportCsv::class)->rows(laporanFilter(), 'subsidi');
    $cells = collect($rows)->flatten()->filter(fn ($cell): bool => is_string($cell) && str_contains($cell, ltrim($dangerous, "=+-@\t\r")));

    expect($cells)->not->toBeEmpty();

    foreach ($cells as $cell) {
        expect($cell[0])->toBe("'");
    }
})->with(['=1+1', '+62812', '-5', '@SUM(A1)', "\tcmd"]);

it('VERIFY: a negative remaining amount from broken data never reduces totals below zero', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 150000, 'sebagian', '2026-10-01');

    $totals = app(SummarizeInvoices::class)->handle(laporanFilter())['totals'];

    expect($totals['outstanding'])->toBe(0)
        ->and($totals['overdue'])->toBe(0)
        ->and($totals['not_yet_due'])->toBe(0);
});

it('VERIFY: summary rows of a cancelled-only period are zero and the rate cell is empty', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000, 0, 'batal');

    $rows = app(ExportFinanceReportCsv::class)->rows(laporanFilter(), 'ringkasan');

    expect($rows)->toHaveCount(3)
        ->and(in_array(500000, $rows[1], true))->toBeFalse();
});

<?php

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

/**
 * 12 santri menunggak dengan tunggakan 100.000 sampai 1.200.000; dua nama diawali karakter rumus.
 */
function laporanCsvArrears(object $f): void
{
    foreach (range(1, 12) as $i) {
        $name = match ($i) {
            12 => '=HYPERLINK("x")',
            11 => '-Ahmad',
            default => "Santri {$i}",
        };

        laporanInvoice($f, laporanStudent($f, attributes: ['name' => $name]), '2026-10-01', $i * 100000, 0, null, '2026-10-10');
    }
}

function laporanCsvRows(string $section, string $from = '2026-10', ?string $to = null): array
{
    return app(ExportFinanceReportCsv::class)->rows(laporanFilter($from, $to), $section);
}

/**
 * @param  list<list<string|int|null>>  $rows
 */
function laporanCsvBlankIndexes(array $rows): array
{
    return array_keys(array_filter($rows, fn (array $row): bool => array_filter($row, fn ($cell): bool => $cell !== null && $cell !== '') === []));
}

it('AC-24: builds the summary rows with a header, one row per month, and a total row', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-08-01', 300000, 100000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 700000, 700000);

    $rows = laporanCsvRows('ringkasan', '2026-08', '2026-10');

    expect($rows)->toHaveCount(1 + 3 + 1)
        ->and($rows[0])->not->toBe([])
        ->and($rows[1][0])->toBe('2026-08')
        ->and($rows[2][0])->toBe('2026-09')
        ->and($rows[3][0])->toBe('2026-10')
        ->and($rows[4][0])->toBe(__('pondok.finance_report.total'))
        ->and(in_array(300000, $rows[1], true))->toBeTrue()
        ->and(in_array(1000000, $rows[4], true))->toBeTrue()
        ->and(in_array(800000, $rows[4], true))->toBeTrue();
});

it('AC-24: lists every overdue student without a limit', function () {
    laporanCsvArrears($this->f);

    $rows = laporanCsvRows('tunggakan');
    $data = array_slice($rows, 1);

    expect($rows)->toHaveCount(13)
        ->and($data[0][1])->toBe("'=HYPERLINK(\"x\")")
        ->and($data[0][2])->toBe($this->f->sltp->name)
        ->and($data[0][3])->toBeIn(['Ya', 'Tidak'])
        ->and($data[0][4])->toBe(1)
        ->and($data[0][5])->toBe('2026-10')
        ->and($data[0][6])->toBe(1200000)
        ->and($data[1][1])->toBe("'-Ahmad")
        ->and($data[2][1])->toBe('Santri 10')
        ->and($data[11][6])->toBe(100000);
});

it('AC-24: builds the scholarship rows per program, per sponsor, and non-scholarship relief', function () {
    laporanSubsidyFixture($this->f);

    $rows = laporanCsvRows('subsidi');
    $blank = laporanCsvBlankIndexes($rows);

    expect($blank)->not->toBe([])
        ->and($rows[1][0])->toBe('Beasiswa Tahfidz')
        ->and($rows[1][1])->toBe('Yayasan Al-Khair')
        ->and(array_slice($rows[1], 2, 3))->toBe([2, 2, 750000])
        ->and($rows[2][0])->toBe('Yatim')
        ->and($rows[3][0])->toBe('Anak Ustadz')
        ->and($rows[3][4])->toBe(100000);

    $last = end($rows);
    expect($last[0])->toBe(__('pondok.finance_report.non_scholarship'))
        ->and(in_array(50000, $last, true))->toBeTrue();
});

it('AC-24: builds the cash rows per day, method, fee type, and a total row', function () {
    laporanCashFixture($this->f);

    $rows = laporanCsvRows('kas');
    $flat = array_map(fn (array $row): array => array_slice($row, 0, 3), $rows);
    $last = end($rows);

    expect($flat)->toContain(['2026-10-14', 1, 150000])
        ->and($flat)->toContain(['2026-10-15', 2, 670000])
        ->and(array_search(['2026-10-14', 1, 150000], $flat, true))->toBeLessThan(array_search(['2026-10-15', 2, 670000], $flat, true))
        ->and(laporanCsvBlankIndexes($rows))->not->toBe([])
        ->and($last[0])->toBe(__('pondok.finance_report.total'))
        ->and(in_array(820000, $last, true))->toBeTrue();

    foreach ($rows as $row) {
        foreach ($row as $cell) {
            expect(is_string($cell) && str_starts_with($cell, 'Rp'))->toBeFalse();
        }
    }
});

it('AC-24: rejects an unknown section', function () {
    laporanAssertRejected(fn () => laporanCsvRows('rahasia'), 'section_invalid', 'section');
    laporanAssertRejected(fn () => app(ExportFinanceReportCsv::class)->handle(laporanFilter(), 'rahasia', User::factory()->create()), 'section_invalid', 'section');
});

it('AC-24: streams a named, BOM-prefixed, semicolon-separated CSV and logs the export once', function () {
    laporanCsvArrears($this->f);
    $user = keuanganUser('bendahara');

    $response = app(ExportFinanceReportCsv::class)->handle(laporanFilter(), 'tunggakan', $user);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Disposition'))->toContain('laporan-keuangan-tunggakan-2026-10-2026-10.csv');

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain(';')
        ->and($content)->toContain('1200000')
        ->and($content)->not->toContain('Rp 1.200.000');

    $activity = Activity::query()->where('log_name', 'keuangan')->where('event', 'exported')->sole();

    expect($activity->causer->is($user))->toBeTrue()
        ->and($activity->properties['section'])->toBe('tunggakan')
        ->and($activity->properties['filter'])->toEqual(['period_from' => '2026-10', 'period_to' => '2026-10', 'unit_id' => null, 'billing_group' => null])
        ->and($activity->properties['rows'])->toBe(12);
});

it('AC-24: only exports data inside the unit scope of the logged-in user', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri SLTP']), '2026-10-01', 100000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri SLTA']), '2026-10-01', 200000, 0, null, '2026-10-10');

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));
    $names = array_column(laporanCsvRows('tunggakan'), 1);

    expect($names)->toContain('Santri SLTP')
        ->and($names)->not->toContain('Santri SLTA');
});

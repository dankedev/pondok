<?php

use App\Actions\Keuangan\ListTopArrears;
use App\Actions\Keuangan\SummarizeCashReceipts;
use App\Actions\Keuangan\SummarizeInvoices;
use App\Actions\Keuangan\SummarizeScholarshipSubsidies;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

/**
 * Satu santri menunggak dengan item keringanan (beasiswa bila `$scholarship`), dan satu pembayaran lewat Action.
 */
function laporanScopeStudent(object $f, string $unit, int $discount, bool $scholarship, int $paid, ?object $program = null): Student
{
    $student = laporanStudent($f, $unit);
    $invoice = laporanInvoice($f, $student, '2026-10-01', 1000000 - $discount, 0, null, '2026-10-10');
    $override = laporanOverride($student, $f->spp, $scholarship ? ($program ?? laporanScholarship("Program {$student->id}", "Donatur {$student->id}")) : null);
    laporanItem($invoice, $f->spp, 1000000, $discount, $override);
    bayarRecord($invoice, $paid);

    return $student;
}

/**
 * Data campuran: SLTP (tagihan 900.000, subsidi 100.000, kas 100.000), SLTA (tagihan 800.000, subsidi 200.000, kas 300.000),
 * dan tagihan pendaftar (600.000, kas 50.000).
 */
function laporanScopeData(object $f): void
{
    laporanScopeStudent($f, 'sltp', 100000, true, 100000);
    laporanScopeStudent($f, 'slta', 200000, true, 300000);
    $applicant = laporanInvoice($f, laporanStudent($f), '2026-10-01', 600000, 0, null, '2026-10-10', 'BULANAN', ['student_id' => null, 'applicant_id' => Applicant::factory()->create(['unit_id' => $f->slta->id])->id]);
    laporanPayment($applicant, 50000, '2026-10-15');
}

/**
 * @return array{invoices: array<string, mixed>, arrears: list<array<string, mixed>>, subsidies: array<string, mixed>, cash: array<string, mixed>}
 */
function laporanRunAll(): array
{
    $filter = laporanFilter('2026-10');

    return [
        'invoices' => app(SummarizeInvoices::class)->handle($filter),
        'arrears' => app(ListTopArrears::class)->handle($filter, 10),
        'subsidies' => app(SummarizeScholarshipSubsidies::class)->handle($filter),
        'cash' => app(SummarizeCashReceipts::class)->handle($filter),
    ];
}

it('AC-15: respects the unit scope of the logged-in user', function () {
    laporanScopeData($this->f);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));
    $scoped = laporanRunAll();

    expect($scoped['invoices']['totals']['billed'])->toBe(900000)
        ->and($scoped['invoices']['totals']['invoice_count'])->toBe(1)
        ->and($scoped['arrears'])->toHaveCount(1)
        ->and($scoped['arrears'][0]['overdue_amount'])->toBe(900000 - 100000)
        ->and($scoped['subsidies']['total_subsidy'])->toBe(100000)
        ->and($scoped['cash']['total'])->toBe(100000)
        ->and($scoped['cash']['payment_count'])->toBe(1);
});

it('AC-15: counts every unit for a bendahara without unit rows', function () {
    laporanScopeData($this->f);

    $this->actingAs(keuanganUser('bendahara'));
    $all = laporanRunAll();

    expect($all['invoices']['totals']['billed'])->toBe(900000 + 800000 + 600000)
        ->and($all['arrears'])->toHaveCount(2)
        ->and($all['subsidies']['total_subsidy'])->toBe(300000)
        ->and($all['cash']['total'])->toBe(450000);
});

it('AC-15: does not filter data without a logged-in user', function () {
    laporanScopeData($this->f);

    $all = laporanRunAll();

    expect($all['invoices']['totals']['billed'])->toBe(2300000)
        ->and($all['arrears'])->toHaveCount(2)
        ->and($all['subsidies']['total_subsidy'])->toBe(300000)
        ->and($all['cash']['total'])->toBe(450000);
});

/**
 * Jumlah query dan SQL yang tercatat saat keempat Action dijalankan.
 *
 * @return array{count: int, sql: list<string>}
 */
function laporanQueryProfile(): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    laporanRunAll();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return ['count' => count($log), 'sql' => array_column($log, 'query')];
}

it('AC-16: runs a constant number of portable queries', function () {
    $f = $this->f;

    foreach (range(1, 3) as $i) {
        laporanScopeStudent($f, 'sltp', 100000, $i !== 2, 100000);
    }

    $small = laporanQueryProfile();

    foreach (range(1, 27) as $i) {
        laporanScopeStudent($f, 'sltp', 100000, $i % 2 === 0, 100000);
    }

    $large = laporanQueryProfile();

    expect(Student::query()->count())->toBe(30)
        ->and($small['count'])->toBeGreaterThan(4)
        ->and($large['count'])->toBe($small['count']);

    foreach ($large['sql'] as $sql) {
        expect(preg_match('/\b(date|strftime|date_format|extract)\s*\(/i', $sql))->toBe(0, "SQL memakai fungsi tanggal: {$sql}");
    }
});

it('AC-17: handles dates stored with and without time', function () {
    $f = $this->f;
    $plain = laporanStudent($f);
    $withTime = laporanStudent($f);
    $now = now()->toDateTimeString();

    $plainId = DB::table('invoices')->insertGetId([
        'invoice_no' => 'INV-202610-90001', 'student_id' => $plain->id, 'academic_year_id' => $f->year->id,
        'period' => '2026-10-01', 'billing_group' => 'BULANAN', 'total' => 500000, 'paid_total' => 0,
        'status' => 'belum', 'due_on' => '2026-10-10', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $timedInvoice = laporanInvoice($f, $withTime, '2026-10-01', 300000, 0, null, '2026-10-10');

    DB::table('payments')->insert([
        'receipt_no' => 'KW-202610-90001', 'invoice_id' => $plainId, 'amount' => 100000, 'paid_on' => '2026-10-12',
        'method' => 'tunai', 'status' => 'diterima', 'created_at' => $now, 'updated_at' => $now,
    ]);
    laporanPayment($timedInvoice, 50000, '2026-10-12');

    $filter = laporanFilter('2026-08', '2026-10');
    $summary = app(SummarizeInvoices::class)->handle($filter);
    $october = collect($summary['periods'])->firstWhere('period', '2026-10');
    $cash = app(SummarizeCashReceipts::class)->handle($filter);

    expect(Invoice::query()->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(2)
        ->and($october['invoice_count'])->toBe(2)
        ->and($october['billed'])->toBe(800000)
        ->and($october['overdue'])->toBe(800000)
        ->and($summary['totals']['overdue_invoice_count'])->toBe(2)
        ->and(collect($summary['periods'])->pluck('period')->all())->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($cash['by_day'])->toHaveCount(1)
        ->and($cash['by_day'][0]['date'])->toBe('2026-10-12')
        ->and($cash['by_day'][0]['count'])->toBe(2)
        ->and($cash['by_day'][0]['amount'])->toBe(150000);
});

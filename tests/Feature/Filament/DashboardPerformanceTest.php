<?php

use App\Models\Cohort;
use App\Models\Student;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-17: keeps the dashboard query count independent of data volume', function () {
    $f = $this->f;
    $user = keuanganUser('super-admin');

    // Pemanasan: cache permission Spatie terisi pada request pertama.
    foreach (dashboardWidgets() as $class) {
        dashboardGet($user)->assertSeeLivewire($class);
    }

    laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000, 100000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 300000);
    $small = dashboardQueryCount(fn () => dashboardGet($user));

    $cohorts = [];
    foreach ([$f->sd, $f->sltp, $f->slta] as $unit) {
        foreach ([2024, 2025, 2026] as $year) {
            $cohorts[] = Cohort::query()->where('unit_id', $unit->id)->where('entry_year', $year)->first()
                ?? Cohort::factory()->create(['unit_id' => $unit->id, 'entry_year' => $year, 'name' => "Angkatan {$year}"]);
        }
    }

    foreach (range(1, 30) as $i) {
        $student = Student::factory()->forCohort($cohorts[$i % 9])->create();
        $invoice = laporanInvoice($f, $student, '2026-10-01', 100000 + $i, $i % 2 === 0 ? 50000 : 0);

        if ($i % 2 === 0) {
            laporanPayment($invoice, 50000, '2026-10-05');
        }
    }

    $large = dashboardQueryCount(fn () => dashboardGet($user));

    expect(Student::query()->count())->toBeGreaterThanOrEqual(32)
        ->and($large)->toBe($small);
});

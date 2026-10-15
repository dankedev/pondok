<?php

use App\Actions\Dashboard\SummarizeActiveStudents;
use App\Models\Cohort;
use App\Models\Unit;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->f = dashboardBoot();
});

function dashboardStudents(): array
{
    return app(SummarizeActiveStudents::class)->handle();
}

function dashboardUnitName(string $code): string
{
    return collect(UnitSeeder::UNITS)->firstWhere('code', $code)['name'];
}

it('AC-11: counts active students per unit and cohort', function () {
    $data = dashboardStudentData($this->f);

    $summary = dashboardStudents();

    expect($summary['total'])->toBe(6)
        ->and(array_column($summary['units'], 'name'))->toBe([dashboardUnitName('SD'), dashboardUnitName('SLTP'), dashboardUnitName('SLTA')])
        ->and(array_column($summary['units'], 'total'))->toBe([0, 5, 1])
        ->and(array_column($summary['units'], 'unit_id'))->toBe([$this->f->sd->id, $this->f->sltp->id, $this->f->slta->id])
        ->and($summary['units'][0]['cohorts'])->toBe([])
        ->and($summary['units'][1]['cohorts'])->toBe([
            ['cohort_id' => $data->cohort2026->id, 'name' => 'Angkatan 2026', 'total' => 3],
            ['cohort_id' => $data->cohort2025->id, 'name' => 'Angkatan 2025', 'total' => 2],
        ])
        ->and($summary['units'][2]['cohorts'])->toBe([
            ['cohort_id' => $data->cohortSlta->id, 'name' => 'Angkatan 2026', 'total' => 1],
        ])
        ->and(array_keys($summary))->toBe(['total', 'units']);
});

it('AC-11: leaves out cohorts without active students', function () {
    dashboardStudentData($this->f);
    Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2024, 'name' => 'Angkatan 2024']);

    expect(array_column(dashboardStudents()['units'][1]['cohorts'], 'name'))->toBe(['Angkatan 2026', 'Angkatan 2025']);
});

it('AC-11: orders units by sort_order rather than id', function () {
    dashboardStudentData($this->f);
    Unit::query()->whereKey($this->f->slta->id)->update(['sort_order' => 0]);

    expect(array_column(dashboardStudents()['units'], 'name')[0])->toBe(dashboardUnitName('SLTA'));
});

it('AC-11: returns every unit with zero when there are no active students', function () {
    $summary = dashboardStudents();

    expect($summary['total'])->toBe(0)
        ->and($summary['units'])->toHaveCount(3)
        ->and(array_column($summary['units'], 'total'))->toBe([0, 0, 0]);
});

it('AC-11: runs at most three queries', function () {
    dashboardStudentData($this->f);

    expect(dashboardQueryCount(fn () => dashboardStudents()))->toBeLessThanOrEqual(3);
});

it('AC-12: respects the unit scope', function () {
    dashboardStudentData($this->f);
    $this->actingAs(keuanganUser('kesantrian', ['SLTP']));

    $summary = dashboardStudents();

    expect($summary['total'])->toBe(5)
        ->and(array_column($summary['units'], 'name'))->toBe([dashboardUnitName('SLTP')])
        ->and(array_column($summary['units'][0]['cohorts'], 'total'))->toBe([3, 2]);
});

it('AC-12: shows every unit without a login or unit rows', function () {
    dashboardStudentData($this->f);

    expect(dashboardStudents()['total'])->toBe(6);

    $this->actingAs(keuanganUser('kesantrian'));

    expect(dashboardStudents()['total'])->toBe(6);
});

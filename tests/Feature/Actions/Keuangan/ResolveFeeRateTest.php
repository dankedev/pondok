<?php

use App\Actions\Asrama\VacateRoom;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;

/**
 * @param  array<string, int>  $amounts  kunci: unit, unit_d1, cohort, cohort_d1
 */
function keuanganLadder(object $f, array $amounts, ?AcademicYear $year = null): void
{
    $year ??= $f->y2025;

    foreach ($amounts as $level => $amount) {
        match ($level) {
            'unit' => keuanganRate($f->type, $year, $f->sltp, $amount),
            'unit_d1' => keuanganRate($f->type, $year, $f->sltp, $amount, null, $f->d1),
            'cohort' => keuanganRate($f->type, $year, $f->sltp, $amount, $f->cohort2025),
            'cohort_d1' => keuanganRate($f->type, $year, $f->sltp, $amount, $f->cohort2025, $f->d1),
        };
    }
}

function keuanganResolve(object $f, ?AcademicYear $year = null, $cohort = null, $dormitory = null, ?int $waveId = null, $unit = null): ?FeeRate
{
    return app(ResolveFeeRate::class)->handle($f->type, $year ?? $f->y2026, ($unit ?? $f->sltp)->id, $cohort?->id, $dormitory?->id, $waveId);
}

it('AC-1, AC-2, AC-3, AC-4: resolves the most specific rate', function (array $amounts, int $expected) {
    $f = keuanganFixture();
    keuanganLadder($f, $amounts);

    expect(keuanganResolve($f, null, $f->cohort2025, $f->d1))->amount->toBe($expected);
})->with([
    'cohort + dormitory' => [['unit' => 400000, 'unit_d1' => 450000, 'cohort' => 500000, 'cohort_d1' => 550000], 550000],
    'cohort' => [['unit' => 400000, 'unit_d1' => 450000, 'cohort' => 500000], 500000],
    'unit + dormitory' => [['unit' => 400000, 'unit_d1' => 450000], 450000],
    'unit' => [['unit' => 400000], 400000],
]);

it('AC-4: resolves the unit rate for a nonresident student', function () {
    $f = keuanganFixture();
    keuanganLadder($f, ['unit' => 400000, 'unit_d1' => 450000]);

    expect(keuanganResolve($f, null, $f->cohort2025, null))->amount->toBe(400000);
});

it('AC-5: never uses a rate for another dormitory', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 600000, null, $f->d2);
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);

    expect(keuanganResolve($f, null, $f->cohort2025, $f->d1))->amount->toBe(400000)
        ->and(keuanganResolve($f, null, $f->cohort2025, null))->amount->toBe(400000);

    FeeRate::query()->whereNull('dormitory_id')->delete();

    expect(keuanganResolve($f, null, $f->cohort2025, null))->toBeNull()
        ->and(keuanganResolve($f, null, $f->cohort2025, $f->d1))->toBeNull();
});

it('AC-6: never uses a rate for another cohort or unit', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 300000, $f->cohort2024);
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);
    keuanganRate($f->type, $f->y2025, $f->sd, 100000);

    expect(keuanganResolve($f, null, $f->cohort2025))->amount->toBe(400000)
        ->and(keuanganResolve($f, null, $f->cohort2024))->amount->toBe(300000)
        ->and(keuanganResolve($f, null, null, null, null, $f->sd))->amount->toBe(100000);

    FeeRate::query()->where('unit_id', $f->sltp->id)->whereNull('cohort_id')->delete();

    expect(keuanganResolve($f, null, $f->cohort2025))->toBeNull();
});

it('AC-7: returns null without a matching rate', function () {
    $f = keuanganFixture();

    expect(keuanganResolve($f, null, $f->cohort2025, $f->d1))->toBeNull();
});

it('AC-8: picks the latest effective year that has started', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2024, $f->sltp, 350000);
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);

    expect(keuanganResolve($f, $f->y2026))->amount->toBe(400000)
        ->and(keuanganResolve($f, $f->y2024))->amount->toBe(350000)
        ->and(keuanganResolve($f, $f->y2025))->amount->toBe(400000);
});

it('AC-9: ignores rates that start in a later year', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2027, $f->sltp, 450000);

    expect(keuanganResolve($f, $f->y2026))->toBeNull();

    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);

    expect(keuanganResolve($f, $f->y2026))->amount->toBe(400000);
});

it('AC-10: includes rates starting in the target year', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);
    keuanganRate($f->type, $f->y2026, $f->sltp, 420000);

    expect(keuanganResolve($f, $f->y2026))->amount->toBe(420000);
});

it('AC-11: prefers specificity over a newer year', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 500000, $f->cohort2025);
    keuanganRate($f->type, $f->y2026, $f->sltp, 600000);

    expect(keuanganResolve($f, $f->y2026, $f->cohort2025))->amount->toBe(500000)
        ->and(keuanganResolve($f, $f->y2026, $f->cohort2026))->amount->toBe(600000);
});

it('AC-12: orders years by start date, not by id', function () {
    $f = keuanganFixture(reverseYears: true);

    expect($f->y2026->id)->toBeLessThan($f->y2025->id);

    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);
    keuanganRate($f->type, $f->y2026, $f->sltp, 420000);

    expect(keuanganResolve($f, $f->y2026))->amount->toBe(420000)
        ->and(keuanganResolve($f, $f->y2025))->amount->toBe(400000);
});

it('AC-13: prefers the admission wave rate and falls back to all waves', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 150000, null, null, 7);
    keuanganRate($f->type, $f->y2025, $f->sltp, 200000);

    expect(keuanganResolve($f, null, null, null, 7))->amount->toBe(150000)
        ->and(keuanganResolve($f, null, null, null, 8))->amount->toBe(200000)
        ->and(keuanganResolve($f, null, null, null, null))->amount->toBe(200000);
});

it('AC-13: never uses a wave rate without a requested wave', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 150000, null, null, 7);

    expect(keuanganResolve($f, null, null, null, null))->toBeNull()
        ->and(keuanganResolve($f, null, null, null, 8))->toBeNull()
        ->and(keuanganResolve($f, null, null, null, 7))->amount->toBe(150000);
});

it('AC-13: a cohort rate without wave beats a unit rate with a wave', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 500000, $f->cohort2025);
    keuanganRate($f->type, $f->y2025, $f->sltp, 150000, null, null, 7);

    expect(keuanganResolve($f, null, $f->cohort2025, null, 7))->amount->toBe(500000);
});

it('AC-14: resolves a student rate from the active room', function () {
    $f = keuanganFixture();
    keuanganLadder($f, ['unit' => 400000, 'unit_d1' => 450000]);
    $student = keuanganStudent($f->cohort2025, $f->d1);

    expect(app(ResolveFeeRate::class)->forStudent($f->type, $student, $f->y2026))->amount->toBe(450000);

    app(VacateRoom::class)->handle($student, Carbon::parse('2026-10-01'));

    expect(app(ResolveFeeRate::class)->forStudent($f->type, $student->fresh(), $f->y2026))->amount->toBe(400000);
});

it('AC-14: uses the student cohort for the student rate', function () {
    $f = keuanganFixture();
    keuanganLadder($f, ['unit' => 400000, 'cohort' => 500000]);

    expect(app(ResolveFeeRate::class)->forStudent($f->type, keuanganStudent($f->cohort2025), $f->y2026))->amount->toBe(500000)
        ->and(app(ResolveFeeRate::class)->forStudent($f->type, keuanganStudent($f->cohort2026), $f->y2026))->amount->toBe(400000);
});

it('AC-14: resolves the same rate regardless of the signed-in user scope', function () {
    $f = keuanganFixture();
    $this->seed(RolePermissionSeeder::class);
    keuanganLadder($f, ['unit' => 400000]);
    $student = keuanganStudent($f->cohort2025);

    $withoutUser = app(ResolveFeeRate::class)->forStudent($f->type, $student, $f->y2026);

    $this->actingAs(keuanganUser('admin-sekolah', ['SD']));
    $withOtherScope = app(ResolveFeeRate::class)->forStudent($f->type, $student, $f->y2026);
    $direct = keuanganResolve($f, null, $f->cohort2025);

    expect($withoutUser)->amount->toBe(400000)
        ->and($withOtherScope?->id)->toBe($withoutUser->id)
        ->and($direct?->id)->toBe($withoutUser->id);
});

it('AC-15: tells whether a rate is a cohort rate', function () {
    $f = keuanganFixture();

    expect(keuanganRate($f->type, $f->y2025, $f->sltp, 500000, $f->cohort2025)->isCohortRate())->toBeTrue()
        ->and(keuanganRate($f->type, $f->y2025, $f->sltp, 400000)->isCohortRate())->toBeFalse();
});

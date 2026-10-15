<?php

use App\Actions\Keuangan\ResolveFeeRate;
use App\Actions\Keuangan\SaveFeeRate;
use App\Actions\Keuangan\SaveFeeType;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use Illuminate\Support\Facades\Schema;

it('VERIFY: priority rejects non-integer input', function (mixed $priority) {
    keuanganAssertRejected(fn () => app(SaveFeeType::class)->handle(keuanganFeeTypeData(['priority' => $priority])), 'priority_range', 'priority');
})->with(['12.5', 'abc', 12.5]);

it('VERIFY: a blank priority becomes 100', function () {
    expect(app(SaveFeeType::class)->handle(keuanganFeeTypeData(['priority' => null]))->priority)->toBe(100);
});

it('VERIFY: rejects an absurdly large amount instead of overflowing', function () {
    $f = keuanganFixture();

    keuanganAssertRejected(fn () => app(SaveFeeRate::class)->handle(keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['amount' => '99999999999999999999'])), 'amount_invalid', 'amount');
});

it('VERIFY: rejects a nonexistent cohort as a validation error', function () {
    $f = keuanganFixture();

    keuanganAssertRejected(fn () => app(SaveFeeRate::class)->handle(keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['cohort_id' => 999999])), 'cohort_unit_mismatch', 'cohort_id');
});

it('VERIFY: a nonexistent fee type does not create a rate', function () {
    $f = keuanganFixture();

    try {
        app(SaveFeeRate::class)->handle(keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['fee_type_id' => 999999]));
    } catch (Throwable) {
    }

    expect(FeeRate::query()->count())->toBe(0);
});

it('VERIFY: rate ties on the same start date fall back to the newest id', function () {
    $f = keuanganFixture();
    $twin = AcademicYear::factory()->create(['name' => '2025/2026 B', 'starts_on' => '2025-07-15', 'ends_on' => '2026-06-30']);
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);
    $newer = keuanganRate($f->type, $twin, $f->sltp, 410000);

    expect(app(ResolveFeeRate::class)->handle($f->type, $f->y2026, $f->sltp->id)->id)->toBe($newer->id);
});

it('VERIFY: inactive fee types still resolve (caller filters)', function () {
    $f = keuanganFixture();
    $inactive = FeeType::factory()->inactive()->create();
    keuanganRate($inactive, $f->y2025, $f->sltp, 100000);

    expect(app(ResolveFeeRate::class)->handle($inactive, $f->y2026, $f->sltp->id))->amount->toBe(100000);
});

it('VERIFY: money columns are not float or decimal', function () {
    foreach (Schema::getColumns('fee_rates') as $column) {
        if ($column['name'] === 'amount') {
            expect($column['type_name'])->not->toContain('float')->not->toContain('decimal')->not->toContain('double');
        }
    }
    expect(FeeRate::factory()->create()->refresh()->amount)->toBeInt();
});

<?php

use App\Actions\Keuangan\SaveFeeRate;
use App\Models\Cohort;
use App\Models\FeeRate;
use App\Models\FeeType;

function keuanganSaveFeeRate(object $f, array $data = [], ?FeeRate $rate = null, ?FeeType $type = null): FeeRate
{
    return app(SaveFeeRate::class)->handle(keuanganFeeRateData($type ?? $f->type, $f->y2025, $f->sltp, $data), $rate);
}

it('AC-19: saves a rate as integer rupiah', function () {
    $f = keuanganFixture();

    $rate = keuanganSaveFeeRate($f, ['amount' => 450000, 'cohort_id' => $f->cohort2025->id, 'dormitory_id' => $f->d1->id]);

    expect($rate->exists)->toBeTrue()
        ->and($rate)->fee_type_id->toBe($f->type->id)->unit_id->toBe($f->sltp->id)
        ->cohort_id->toBe($f->cohort2025->id)->dormitory_id->toBe($f->d1->id)->amount->toBe(450000)
        ->and($rate->effective_from_year_id)->toBe($f->y2025->id);
});

it('AC-19: accepts zero and digit strings as amount', function () {
    $f = keuanganFixture();

    $free = keuanganSaveFeeRate($f, ['amount' => 0]);
    $string = keuanganSaveFeeRate($f, ['amount' => '1500000', 'cohort_id' => $f->cohort2025->id]);

    expect($free->fresh()->amount)->toBe(0)
        ->and($string->fresh()->amount)->toBe(1500000)
        ->and(FeeRate::query()->count())->toBe(2);
});

it('AC-20: rejects the rate', function (Closure $data, string $reason, string $field) {
    $f = keuanganFixture();
    $payload = $data($f);
    $type = $payload['__type'] ?? $f->type;
    unset($payload['__type']);

    keuanganAssertRejected(fn () => keuanganSaveFeeRate($f, $payload, null, $type), $reason, $field);

    expect(FeeRate::query()->count())->toBe(0);
})->with([
    'negative amount' => [fn () => ['amount' => -1], 'amount_invalid', 'amount'],
    'decimal amount' => [fn () => ['amount' => '1500.50'], 'amount_invalid', 'amount'],
    'null amount' => [fn () => ['amount' => null], 'amount_invalid', 'amount'],
    'cohort of another unit' => [fn (object $f) => ['cohort_id' => Cohort::factory()->create(['unit_id' => $f->sd->id])->id], 'cohort_unit_mismatch', 'cohort_id'],
    'dormitory for a nonresident fee' => [fn (object $f) => [
        'dormitory_id' => $f->d1->id,
        '__type' => FeeType::factory()->nonResident()->create(),
    ], 'dormitory_rate_for_nonresident_fee', 'dormitory_id'],
]);

it('AC-21: rejects a duplicate rate combination', function (array $keys) {
    $f = keuanganFixture();
    $base = [
        'cohort_id' => in_array('cohort', $keys, true) ? $f->cohort2025->id : null,
        'dormitory_id' => in_array('dormitory', $keys, true) ? $f->d1->id : null,
        'admission_wave_id' => in_array('wave', $keys, true) ? 7 : null,
    ];
    keuanganSaveFeeRate($f, $base);

    keuanganAssertRejected(fn () => keuanganSaveFeeRate($f, [...$base, 'amount' => 999000]), 'duplicate_rate', 'effective_from_year_id');

    expect(FeeRate::query()->count())->toBe(1);
})->with([
    'unit default' => [[]],
    'cohort' => [['cohort']],
    'cohort + dormitory' => [['cohort', 'dormitory']],
    'dormitory' => [['dormitory']],
    'wave' => [['wave']],
]);

it('AC-21: allows rates that differ in one dimension', function () {
    $f = keuanganFixture();
    keuanganSaveFeeRate($f);

    keuanganSaveFeeRate($f, ['effective_from_year_id' => $f->y2026->id]);
    keuanganSaveFeeRate($f, ['cohort_id' => $f->cohort2025->id]);
    keuanganSaveFeeRate($f, ['dormitory_id' => $f->d1->id]);
    keuanganSaveFeeRate($f, ['admission_wave_id' => 7]);
    keuanganSaveFeeRate($f, [], null, FeeType::factory()->create());

    expect(FeeRate::query()->count())->toBe(6);
});

it('AC-21: updates a rate without clashing with itself', function () {
    $f = keuanganFixture();
    $rate = keuanganSaveFeeRate($f, ['amount' => 400000]);

    $updated = keuanganSaveFeeRate($f, ['amount' => 450000], $rate);

    expect($updated->id)->toBe($rate->id)->and($rate->fresh()->amount)->toBe(450000)
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-21: rejects updating a rate into another existing combination', function () {
    $f = keuanganFixture();
    keuanganSaveFeeRate($f);
    $cohortRate = keuanganSaveFeeRate($f, ['cohort_id' => $f->cohort2025->id]);

    keuanganAssertRejected(fn () => keuanganSaveFeeRate($f, ['cohort_id' => null], $cohortRate), 'duplicate_rate', 'effective_from_year_id');

    expect($cohortRate->fresh()->cohort_id)->toBe($f->cohort2025->id);
});

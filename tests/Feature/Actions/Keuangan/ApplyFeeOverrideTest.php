<?php

use App\Actions\Keuangan\ApplyFeeOverride;

function keringananApply(?int $base, ?array $override = null): ?array
{
    $model = $override === null ? null : keringananOverride($override);

    return app(ApplyFeeOverride::class)->handle($base, $model);
}

it('AC-1: keeps the base amount without an override', function () {
    expect(app(ApplyFeeOverride::class)->handle(400000, null))
        ->toBe(['base_amount' => 400000, 'discount_amount' => 0, 'amount' => 400000, 'fee_override_id' => null]);
});

it('AC-2: applies the nominal mode', function (int $value, int $amount, int $discount) {
    $override = keringananOverride(['mode' => 'nominal', 'value' => $value]);

    expect(app(ApplyFeeOverride::class)->handle(400000, $override))
        ->toBe(['base_amount' => 400000, 'discount_amount' => $discount, 'amount' => $amount, 'fee_override_id' => $override->id]);
})->with([
    'pay 250.000' => [250000, 250000, 150000],
    'free' => [0, 0, 400000],
    'above the rate is capped' => [500000, 400000, 0],
]);

it('AC-3: applies the discount mode', function (int $value, int $amount, int $discount) {
    $override = keringananOverride(['mode' => 'potongan', 'value' => $value]);

    expect(app(ApplyFeeOverride::class)->handle(400000, $override))
        ->toBe(['base_amount' => 400000, 'discount_amount' => $discount, 'amount' => $amount, 'fee_override_id' => $override->id]);
})->with([
    'minus 100.000' => [100000, 300000, 100000],
    'above the rate' => [500000, 0, 400000],
]);

it('AC-4: applies the percent mode and rounds the discount down', function (int $base, int $percent, int $discount, int $amount) {
    $override = keringananOverride(['mode' => 'persen', 'value' => $percent]);

    expect(app(ApplyFeeOverride::class)->handle($base, $override))
        ->toBe(['base_amount' => $base, 'discount_amount' => $discount, 'amount' => $amount, 'fee_override_id' => $override->id]);
})->with([
    '50 percent' => [400000, 50, 200000, 200000],
    '100 percent' => [400000, 100, 400000, 0],
    '0 percent' => [400000, 0, 0, 400000],
    'rounds 166.666,5 down' => [333333, 50, 166666, 166667],
    'rounds 33.000,33 down' => [100001, 33, 33000, 67001],
]);

it('AC-5: handles a missing base rate', function () {
    $nominal = keringananOverride(['mode' => 'nominal', 'value' => 250000]);
    $free = keringananOverride(['mode' => 'nominal', 'value' => 0]);

    expect(app(ApplyFeeOverride::class)->handle(null, $nominal))
        ->toBe(['base_amount' => 250000, 'discount_amount' => 0, 'amount' => 250000, 'fee_override_id' => $nominal->id])
        ->and(app(ApplyFeeOverride::class)->handle(null, $free))
        ->toBe(['base_amount' => 0, 'discount_amount' => 0, 'amount' => 0, 'fee_override_id' => $free->id])
        ->and(keringananApply(null, ['mode' => 'potongan', 'value' => 100000]))->toBeNull()
        ->and(keringananApply(null, ['mode' => 'persen', 'value' => 50]))->toBeNull()
        ->and(app(ApplyFeeOverride::class)->handle(null, null))->toBeNull();
});

it('AC-6: keeps base minus discount equal to the amount', function (?int $base, ?array $override) {
    $result = keringananApply($base, $override);

    expect($result)->not->toBeNull()
        ->and($result['base_amount'] - $result['discount_amount'])->toBe($result['amount'])
        ->and($result['base_amount'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($result['discount_amount'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($result['amount'])->toBeInt()->toBeGreaterThanOrEqual(0);
})->with([
    'no override' => [400000, null],
    'nominal' => [400000, ['mode' => 'nominal', 'value' => 250000]],
    'nominal above' => [400000, ['mode' => 'nominal', 'value' => 500000]],
    'nominal free' => [400000, ['mode' => 'nominal', 'value' => 0]],
    'discount' => [400000, ['mode' => 'potongan', 'value' => 100000]],
    'discount above' => [400000, ['mode' => 'potongan', 'value' => 500000]],
    'percent' => [333333, ['mode' => 'persen', 'value' => 50]],
    'percent odd' => [100001, ['mode' => 'persen', 'value' => 33]],
    'no base nominal' => [null, ['mode' => 'nominal', 'value' => 250000]],
    'no base free' => [null, ['mode' => 'nominal', 'value' => 0]],
]);

it('AC-7: follows a rate increase for discount and percent modes', function () {
    $discount = keringananOverride(['mode' => 'potongan', 'value' => 100000]);
    $percent = keringananOverride(['mode' => 'persen', 'value' => 50]);
    $nominal = keringananOverride(['mode' => 'nominal', 'value' => 250000]);
    $apply = app(ApplyFeeOverride::class);

    expect($apply->handle(400000, $discount)['amount'])->toBe(300000)
        ->and($apply->handle(450000, $discount)['amount'])->toBe(350000)
        ->and($apply->handle(400000, $percent)['amount'])->toBe(200000)
        ->and($apply->handle(450000, $percent)['amount'])->toBe(225000)
        ->and($apply->handle(400000, $nominal)['amount'])->toBe(250000)
        ->and($apply->handle(450000, $nominal)['amount'])->toBe(250000);
});

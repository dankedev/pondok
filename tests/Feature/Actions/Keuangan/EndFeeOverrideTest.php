<?php

use App\Actions\Keuangan\EndFeeOverride;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Models\FeeOverride;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
});

function keringananEndable(object $f, ?string $until = null): FeeOverride
{
    return keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => $until]);
}

it('AC-21: ends an override without deleting it', function () {
    $f = keringananFixture();
    $override = keringananEndable($f);

    app(EndFeeOverride::class)->handle($override, CarbonImmutable::parse('2026-10-31'));

    $resolver = app(ResolveFeeOverride::class);

    expect($override->fresh()->valid_until->toDateString())->toBe('2026-10-31')
        ->and(FeeOverride::query()->count())->toBe(1)
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-11-01')))->toBeNull()
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-10-01'))?->id)->toBe($override->id);
});

it('AC-21: ends today by default', function () {
    $override = keringananEndable(keringananFixture());

    app(EndFeeOverride::class)->handle($override);

    expect($override->fresh()->valid_until->toDateString())->toBe('2026-10-15');
});

it('AC-21: shortens a planned end date', function () {
    $override = keringananEndable(keringananFixture(), '2026-12-31');

    app(EndFeeOverride::class)->handle($override, CarbonImmutable::parse('2026-10-31'));

    expect($override->fresh()->valid_until->toDateString())->toBe('2026-10-31');
});

it('AC-22: rejects ending', function (?string $until, string $endOn, string $reason) {
    $override = keringananEndable(keringananFixture(), $until);

    keuanganAssertRejected(fn () => app(EndFeeOverride::class)->handle($override, CarbonImmutable::parse($endOn)), $reason, 'valid_until');

    expect($override->fresh()->valid_until?->toDateString())->toBe($until);
})->with([
    'before the start' => [null, '2026-06-30', 'end_before_start'],
    'already ended earlier' => ['2026-09-30', '2026-10-31', 'override_already_ended'],
    'ended on the same day' => ['2026-09-30', '2026-09-30', 'override_already_ended'],
]);

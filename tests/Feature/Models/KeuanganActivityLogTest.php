<?php

use App\Actions\Keuangan\EndFeeOverride;
use App\Actions\Keuangan\SaveFeeOverride;
use App\Actions\Keuangan\SaveFeeRate;
use App\Actions\Keuangan\SaveFeeType;
use App\Actions\Keuangan\SaveScholarship;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('AC-24: logs rate creation with the causer', function () {
    $f = keuanganFixture();
    $bendahara = keuanganUser('bendahara');
    $this->actingAs($bendahara);

    $rate = app(SaveFeeRate::class)->handle(keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['amount' => 400000]));

    $activity = Activity::query()->where('subject_type', $rate->getMorphClass())->where('subject_id', $rate->id)->sole();

    expect($activity)
        ->log_name->toBe('keuangan')
        ->event->toBe('created')
        ->causer_id->toBe($bendahara->id)
        ->and($activity->subject->is($rate))->toBeTrue()
        ->and($activity->properties['attributes']['amount'])->toBe(400000)
        ->and($activity->properties['attributes'])->not->toHaveKeys(['cohort_key', 'dormitory_key', 'wave_key', 'created_at', 'updated_at']);
});

it('AC-25: logs only dirty rate changes', function () {
    $f = keuanganFixture();
    $this->actingAs(keuanganUser('bendahara'));
    $rate = app(SaveFeeRate::class)->handle(keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['amount' => 400000]));
    $rateData = keuanganFeeRateData($f->type, $f->y2025, $f->sltp, ['amount' => 450000]);

    app(SaveFeeRate::class)->handle($rateData, $rate);

    $updated = Activity::query()->where('subject_id', $rate->id)->where('event', 'updated')->sole();

    expect($updated->log_name)->toBe('keuangan')
        ->and($updated->properties['old']['amount'])->toBe(400000)
        ->and($updated->properties['attributes']['amount'])->toBe(450000);

    $before = Activity::query()->count();

    app(SaveFeeRate::class)->handle($rateData, $rate->fresh());

    expect(Activity::query()->count())->toBe($before);
});

it('AC-26: logs fee type changes', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $feeType = app(SaveFeeType::class)->handle(keuanganFeeTypeData());
    app(SaveFeeType::class)->handle(keuanganFeeTypeData(['name' => 'SPP Bulanan']), $feeType);

    $activities = Activity::query()->where('subject_id', $feeType->id)->where('log_name', 'keuangan')->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities->every(fn (Activity $activity): bool => $activity->subject->is($feeType)))->toBeTrue()
        ->and($activities[1]->properties['attributes']['name'])->toBe('SPP Bulanan');
});

it('AC-25: logs override creation with the causer', function () {
    $f = keringananFixture();
    $bendahara = keuanganUser('bendahara');
    $this->actingAs($bendahara);

    $override = app(SaveFeeOverride::class)->handle(keringananOverrideData($f), $bendahara);

    $activity = Activity::query()->where('subject_type', $override->getMorphClass())->where('subject_id', $override->id)->sole();

    expect($activity)
        ->log_name->toBe('keuangan')
        ->event->toBe('created')
        ->causer_id->toBe($bendahara->id)
        ->and($activity->subject->is($override))->toBeTrue()
        ->and($activity->properties['attributes'])->toHaveKeys(['mode', 'value', 'valid_from'])
        ->and($activity->properties['attributes']['mode'])->toBe('nominal')
        ->and($activity->properties['attributes']['value'])->toBe(250000);
});

it('AC-25: logs override changes and endings', function () {
    $f = keringananFixture();
    $bendahara = keuanganUser('bendahara');
    $this->actingAs($bendahara);
    $override = app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['value' => 250000]), $bendahara);
    $data = keringananOverrideData($f, ['value' => 200000]);

    app(SaveFeeOverride::class)->handle($data, $bendahara, $override);

    $updated = Activity::query()->where('subject_id', $override->id)->where('subject_type', $override->getMorphClass())->where('event', 'updated')->sole();

    expect($updated->log_name)->toBe('keuangan')
        ->and($updated->properties['old']['value'])->toBe(250000)
        ->and($updated->properties['attributes']['value'])->toBe(200000);

    $before = Activity::query()->count();

    app(SaveFeeOverride::class)->handle($data, $bendahara, $override->fresh());

    expect(Activity::query()->count())->toBe($before);

    app(EndFeeOverride::class)->handle($override->fresh(), CarbonImmutable::parse('2026-10-31'));

    $ended = Activity::query()->where('subject_id', $override->id)->where('subject_type', $override->getMorphClass())->where('event', 'updated')->orderByDesc('id')->first();

    expect(Activity::query()->count())->toBe($before + 1)
        ->and($ended->log_name)->toBe('keuangan')
        ->and($ended->properties['attributes'])->toHaveKey('valid_until');
});

it('AC-26: logs scholarship changes', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $scholarship = app(SaveScholarship::class)->handle(keringananScholarshipData());
    app(SaveScholarship::class)->handle(keringananScholarshipData(['default_value' => 75]), $scholarship);

    $activities = Activity::query()->where('subject_id', $scholarship->id)->where('subject_type', $scholarship->getMorphClass())->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities->pluck('log_name')->unique()->all())->toBe(['keuangan'])
        ->and($activities->every(fn (Activity $activity): bool => $activity->subject->is($scholarship)))->toBeTrue()
        ->and($activities[1]->properties['attributes']['default_value'])->toBe(75);
});

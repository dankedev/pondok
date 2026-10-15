<?php

use App\Actions\Keuangan\SaveFeeRate;
use App\Actions\Keuangan\SaveFeeType;
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

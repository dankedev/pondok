<?php

use App\Actions\Keuangan\SaveFeeType;
use App\Models\FeeRate;
use App\Models\FeeType;

function keuanganSaveFeeType(array $data = [], ?FeeType $feeType = null): FeeType
{
    return app(SaveFeeType::class)->handle(keuanganFeeTypeData($data), $feeType);
}

it('AC-16: normalizes and saves a fee type', function () {
    $feeType = keuanganSaveFeeType(['code' => ' spp ', 'billing_group' => 'bulanan', 'applies_to' => 'mukim']);

    expect($feeType->exists)->toBeTrue()
        ->and($feeType)->code->toBe('SPP')->billing_group->toBe('BULANAN')->applies_to->toBe('mukim')->frequency->toBe('bulanan')
        ->and($feeType->priority)->toBe(100)
        ->and($feeType->is_active)->toBeTrue()
        ->and(FeeType::query()->count())->toBe(1);
});

it('AC-16: defaults the priority to 100 when it is not given', function () {
    $data = keuanganFeeTypeData();
    unset($data['priority']);

    expect(app(SaveFeeType::class)->handle($data)->priority)->toBe(100);
});

it('AC-16: replaces spaces in the billing group with underscores', function () {
    expect(keuanganSaveFeeType(['billing_group' => 'tagihan bulanan'])->billing_group)->toBe('TAGIHAN_BULANAN');
});

it('AC-17: rejects the fee type', function (array $data, string $reason, string $field, bool $existing) {
    if ($existing) {
        FeeType::factory()->create(['code' => 'SPP']);
    }
    $before = FeeType::query()->count();

    keuanganAssertRejected(fn () => keuanganSaveFeeType($data), $reason, $field);

    expect(FeeType::query()->count())->toBe($before);
})->with([
    'code format' => [['code' => 'SP P!'], 'code_format', 'code', false],
    'code taken' => [['code' => 'spp'], 'code_taken', 'code', true],
    'invalid frequency' => [['frequency' => 'harian'], 'invalid_frequency', 'frequency', false],
    'invalid applies_to' => [['applies_to' => 'tamu'], 'invalid_applies_to', 'applies_to', false],
    'priority negative' => [['priority' => -1], 'priority_range', 'priority', false],
    'priority too large' => [['priority' => 70000], 'priority_range', 'priority', false],
    'billing group empty' => [['billing_group' => ''], 'billing_group_format', 'billing_group', false],
]);

it('AC-17: does not change a fee type when an update is rejected', function () {
    $feeType = keuanganSaveFeeType();

    keuanganAssertRejected(fn () => keuanganSaveFeeType(['name' => 'Berubah', 'frequency' => 'harian'], $feeType), 'invalid_frequency', 'frequency');

    expect($feeType->fresh())->name->toBe('SPP')->frequency->toBe('bulanan');
});

it('AC-17: allows a fee type to keep its own code when updating', function () {
    $feeType = keuanganSaveFeeType();

    $updated = keuanganSaveFeeType(['name' => 'SPP Bulanan'], $feeType);

    expect($updated->id)->toBe($feeType->id)->and($updated->fresh()->name)->toBe('SPP Bulanan')
        ->and(FeeType::query()->count())->toBe(1);
});

it('AC-18: rejects switching to nonmukim when dormitory rates exist', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 450000, null, $f->d1);

    keuanganAssertRejected(
        fn () => app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'ASRAMA', 'name' => 'Asrama', 'applies_to' => 'nonmukim']), $f->type),
        'has_dormitory_rates',
        'applies_to',
    );

    expect($f->type->fresh()->applies_to)->toBe('mukim');
});

it('AC-18: allows switching to nonmukim without dormitory rates', function () {
    $f = keuanganFixture();
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000);

    $updated = app(SaveFeeType::class)->handle(keuanganFeeTypeData(['code' => 'ASRAMA', 'name' => 'Asrama', 'applies_to' => 'nonmukim']), $f->type);

    expect($updated->fresh()->applies_to)->toBe('nonmukim')
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-5 (F010): separates psb billing groups from other frequencies', function (string $existingFrequency, string $existingGroup, bool $existingActive, string $frequency, string $group, bool $conflict) {
    FeeType::factory()->create(['code' => 'ADA', 'frequency' => $existingFrequency, 'billing_group' => $existingGroup, 'is_active' => $existingActive]);

    $save = fn () => keuanganSaveFeeType(['code' => 'BARU', 'frequency' => $frequency, 'billing_group' => $group]);

    if ($conflict) {
        keuanganAssertRejected($save, 'billing_group_frequency_conflict', 'billing_group');

        expect(FeeType::query()->where('code', 'BARU')->exists())->toBeFalse();

        return;
    }

    expect($save()->exists)->toBeTrue()
        ->and(FeeType::query()->count())->toBe(2);
})->with([
    'saat_daftar into a monthly group' => ['bulanan', 'BULANAN', true, 'saat_daftar', 'BULANAN', true],
    'saat_diterima into a saat_daftar group' => ['saat_daftar', 'PSB_DAFTAR', true, 'saat_diterima', 'PSB_DAFTAR', true],
    'monthly into a saat_daftar group' => ['saat_daftar', 'PSB_DAFTAR', true, 'bulanan', 'PSB_DAFTAR', true],
    'inactive types still count' => ['saat_daftar', 'PSB_DAFTAR', false, 'bulanan', 'PSB_DAFTAR', true],
    'second saat_daftar in the same group' => ['saat_daftar', 'PSB_DAFTAR', true, 'saat_daftar', 'PSB_DAFTAR', false],
    'yearly beside monthly keeps the old behaviour' => ['bulanan', 'BULANAN', true, 'tahunan', 'BULANAN', false],
]);

it('AC-5 (F010): rejects editing a fee type into a conflicting frequency', function () {
    $formulir = FeeType::factory()->create(['code' => 'FORMULIR', 'frequency' => 'saat_daftar', 'billing_group' => 'PSB_DAFTAR']);
    FeeType::factory()->create(['code' => 'SERAGAM', 'frequency' => 'saat_daftar', 'billing_group' => 'PSB_DAFTAR']);

    keuanganAssertRejected(
        fn () => keuanganSaveFeeType(['code' => 'FORMULIR', 'frequency' => 'bulanan', 'billing_group' => 'PSB_DAFTAR'], $formulir),
        'billing_group_frequency_conflict',
        'billing_group',
    );

    expect($formulir->fresh()->frequency)->toBe('saat_daftar');
});

it('AC-5 (F010): allows editing the only fee type of a group to another frequency', function () {
    $formulir = FeeType::factory()->create(['code' => 'FORMULIR', 'frequency' => 'saat_daftar', 'billing_group' => 'PSB_DAFTAR']);

    keuanganSaveFeeType(['code' => 'FORMULIR', 'frequency' => 'bulanan', 'billing_group' => 'PSB_DAFTAR'], $formulir);

    expect($formulir->fresh()->frequency)->toBe('bulanan');
});

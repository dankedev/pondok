<?php

use App\Models\Setting;
use Database\Seeders\SettingSeeder;

it('AC-6: enables dokumen on a fresh install', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('dokumen'))->toBeTrue()
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue()
        ->and(Setting::moduleEnabled('psb'))->toBeTrue()
        ->and(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('konten'))->toBeTrue()
        ->and(Setting::moduleEnabled('prestasi'))->toBeFalse();
});

it('AC-6: does not overwrite a dokumen switch that was turned off', function () {
    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('dokumen'))->toBeTrue();

    Setting::set('modules.dokumen.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('dokumen'))->toBeFalse()
        ->and(Setting::moduleEnabled('psb'))->toBeTrue();
});

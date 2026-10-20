<?php

use App\Models\Setting;
use Database\Seeders\SettingSeeder;

it('AC-1 (F040): seeds the other settings without overriding and never seeds the old percent', function () {
    expect(Setting::query()->count())->toBe(0);

    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('psb.min_paid_percent'))->toBeNull()
        ->and(Setting::query()->find('students.nis_format')?->value)->toBe('{YYYY}{NNNN}');

    Setting::set('students.nis_format', '{UNIT}{NNNN}');

    $this->seed(SettingSeeder::class);

    expect(Setting::query()->where('key', 'psb.min_paid_percent')->count())->toBe(0)
        ->and(Setting::query()->find('students.nis_format')->value)->toBe('{UNIT}{NNNN}');
});

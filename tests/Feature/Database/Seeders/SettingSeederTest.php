<?php

use App\Models\Setting;
use Database\Seeders\SettingSeeder;

it('AC-4 (F012): seeds re-registration settings without overriding', function () {
    expect(Setting::query()->count())->toBe(0);

    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('psb.min_paid_percent')?->value)->toBe(50)
        ->and(Setting::query()->find('students.nis_format')?->value)->toBe('{YYYY}{NNNN}');

    Setting::set('psb.min_paid_percent', 100);
    Setting::set('students.nis_format', '{UNIT}{NNNN}');

    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('psb.min_paid_percent')->value)->toBe(100)
        ->and(Setting::query()->find('students.nis_format')->value)->toBe('{UNIT}{NNNN}')
        ->and(Setting::query()->where('key', 'psb.min_paid_percent')->count())->toBe(1);
});

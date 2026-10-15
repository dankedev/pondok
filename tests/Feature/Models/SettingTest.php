<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

it('reads a setting from the database only once per request', function () {
    Setting::set('modules.akademik.enabled', true);
    Setting::forget('modules.akademik.enabled');

    DB::enableQueryLog();
    foreach (range(1, 5) as $ignored) {
        Setting::moduleEnabled('akademik');
    }

    $settingQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'settings'));

    expect($settingQueries)->toHaveCount(1);
});

it('returns the new value right after it is changed', function () {
    expect(Setting::moduleEnabled('asrama'))->toBeFalse();

    Setting::set('modules.asrama.enabled', true);
    expect(Setting::moduleEnabled('asrama'))->toBeTrue();

    Setting::query()->findOrFail('modules.asrama.enabled')->delete();
    expect(Setting::moduleEnabled('asrama'))->toBeFalse();
});

it('falls back to the default for a missing key', function () {
    expect(Setting::get('school.motto', 'Ilmu dan amal'))->toBe('Ilmu dan amal');
});

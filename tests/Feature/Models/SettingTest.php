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

it('AC-1: getMany loads missing keys in one query and caches absent keys as null', function () {
    Setting::set('school.name', 'Pondok A');
    Setting::set('school.tagline', 'Motto');
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    $values = Setting::getMany(['school.name', 'school.tagline', 'school.tidak-ada']);
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'settings'));

    expect($values)->toBe(['school.name' => 'Pondok A', 'school.tagline' => 'Motto', 'school.tidak-ada' => null])
        ->and($queries)->toHaveCount(1);

    DB::flushQueryLog();
    Setting::getMany(['school.name', 'school.tidak-ada']);
    Setting::get('school.tagline');
    Setting::get('school.tidak-ada');

    expect(DB::getQueryLog())->toBe([]);
});

it('AC-1: getMany only queries the keys that are not cached yet and sees later changes', function () {
    Setting::set('school.name', 'Pondok A');
    Setting::set('school.tagline', 'Motto');
    app()->forgetScopedInstances();
    Setting::get('school.name');

    DB::enableQueryLog();
    Setting::getMany(['school.name', 'school.tagline']);
    $log = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'settings'));

    expect($log)->toHaveCount(1)->and($log->first()['bindings'])->toBe(['school.tagline']);

    Setting::set('school.tagline', 'Baru');

    expect(Setting::getMany(['school.tagline']))->toBe(['school.tagline' => 'Baru']);
});

<?php

use App\Actions\Dashboard\SummarizeSystemStatus;
use App\Enums\Module;
use App\Models\AcademicYear;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-14: summarizes system status', function () {
    dashboardEnableThreeModules();
    User::factory()->count(4)->create();
    User::factory()->inactive()->create();

    $summary = app(SummarizeSystemStatus::class)->handle();

    expect($summary)->toMatchArray([
        'active_academic_year' => '2026/2027',
        'enabled_module_count' => 3,
        'module_count' => count(Module::cases()),
        'active_user_count' => 4,
    ])->and(array_keys($summary))->toHaveCount(4);
});

it('AC-14: handles a missing active academic year', function () {
    AcademicYear::query()->update(['is_active' => false]);

    expect(app(SummarizeSystemStatus::class)->handle()['active_academic_year'])->toBeNull();
});

it('AC-2: counts the modules of the enum and ignores a legacy perizinan row', function () {
    dashboardEnableThreeModules();
    Setting::set('school.name', 'Ma\'had Al-Kitab');
    Setting::set('modules.perizinan.enabled', true);

    $summary = app(SummarizeSystemStatus::class)->handle();

    expect($summary['module_count'])->toBe(count(Module::cases()))
        ->and($summary['enabled_module_count'])->toBe(3);
});

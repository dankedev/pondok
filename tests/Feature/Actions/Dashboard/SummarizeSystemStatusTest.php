<?php

use App\Actions\Dashboard\SummarizeSystemStatus;
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
        'module_count' => 8,
        'active_user_count' => 4,
    ])->and(array_keys($summary))->toHaveCount(4);
});

it('AC-14: handles a missing active academic year', function () {
    AcademicYear::query()->update(['is_active' => false]);

    expect(app(SummarizeSystemStatus::class)->handle()['active_academic_year'])->toBeNull();
});

it('AC-2: counts the modules of the enum and ignores legacy tahfidz and perizinan rows', function () {
    dashboardEnableThreeModules();
    Setting::set('school.name', 'Ma\'had Al-Khair');
    Setting::set('modules.tahfidz.enabled', true);
    Setting::set('modules.perizinan.enabled', true);

    $summary = app(SummarizeSystemStatus::class)->handle();

    expect($summary['module_count'])->toBe(8)
        ->and($summary['enabled_module_count'])->toBe(3);
});

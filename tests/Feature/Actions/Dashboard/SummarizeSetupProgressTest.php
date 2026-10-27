<?php

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Core\SetModuleEnabled;
use App\Actions\Dashboard\SummarizeSetupProgress;
use App\Enums\Module;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\FeeRate;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\InstallSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(InstallSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Status langkah setup: key => selesai.
 *
 * @return array<string, bool>
 */
function setupSteps(): array
{
    return collect(app(SummarizeSetupProgress::class)->handle()['steps'])->pluck('done', 'key')->all();
}

/**
 * Ganti hasil pemeriksaan `pondok:doctor` dengan status tetap per key.
 *
 * @param  array<string, string>  $statuses
 */
function setupHealth(array $statuses): void
{
    test()->mock(CheckSystemHealth::class)
        ->shouldReceive('only')
        ->andReturnUsing(fn (array $keys): array => array_map(
            fn (string $key): array => ['key' => $key, 'status' => $statuses[$key] ?? 'warning', 'message' => ''],
            $keys,
        ));
}

it('AC-1: a fresh installation is below 100% with pending steps', function () {
    $progress = app(SummarizeSetupProgress::class)->handle();
    $steps = setupSteps();

    expect($progress['percent'])->toBeLessThan(100)
        ->and($progress['total'])->toBe(10)
        ->and(array_keys($steps))->toBe(['profile', 'units', 'academic_year', 'modules', 'classrooms', 'students', 'staff', 'fee_rates', 'mail', 'cron'])
        ->and($steps)->toMatchArray([
            'profile' => false,
            'units' => true,
            'academic_year' => true,
            'modules' => false,
            'classrooms' => false,
            'students' => false,
            'staff' => false,
            'fee_rates' => false,
        ]);
});

it('AC-2: profile needs a name, a logo and contact data', function () {
    Setting::set('school.logo_path', 'school/logo.png');
    expect(setupSteps()['profile'])->toBeFalse();

    Setting::set('school.email', 'tu@pondok.sch.id');
    expect(setupSteps()['profile'])->toBeTrue();
});

it('AC-2: academic year follows the active year', function () {
    AcademicYear::query()->update(['is_active' => false]);

    expect(setupSteps()['academic_year'])->toBeFalse();
});

it('AC-2: modules are done once a module is turned off', function () {
    Setting::set('modules.kedisiplinan.enabled', false);

    expect(setupSteps()['modules'])->toBeTrue();
});

it('AC-2: modules are done once the switch was used, even with every module back on', function () {
    $admin = User::factory()->create()->assignRole('super-admin');
    app(SetModuleEnabled::class)->handle($admin, Module::Kedisiplinan, false);
    app(SetModuleEnabled::class)->handle($admin, Module::Kedisiplinan, true);

    expect(Module::Kedisiplinan->isEnabled())->toBeTrue()
        ->and(setupSteps()['modules'])->toBeTrue();
});

it('AC-2: data steps follow their records', function (string $key, Closure $create) {
    expect(setupSteps()[$key])->toBeFalse();

    $create();

    expect(setupSteps()[$key])->toBeTrue();
})->with([
    'classrooms' => ['classrooms', fn () => Classroom::factory()->create()],
    'students' => ['students', fn () => Student::factory()->create()],
    'fee_rates' => ['fee_rates', fn () => FeeRate::factory()->create()],
]);

it('AC-2: staff needs a user with a role other than super-admin or wali', function () {
    User::factory()->create()->assignRole('super-admin');
    User::factory()->create()->assignRole('wali');
    User::factory()->create();
    expect(setupSteps()['staff'])->toBeFalse();

    User::factory()->create()->assignRole('bendahara');
    expect(setupSteps()['staff'])->toBeTrue();
});

it('AC-2: mail and cron follow the pondok:doctor checks', function () {
    setupHealth(['mail' => 'warning', 'scheduler' => 'ok', 'queue' => 'warning']);
    expect(setupSteps())->toMatchArray(['mail' => false, 'cron' => false]);

    setupHealth(['mail' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok']);
    expect(setupSteps())->toMatchArray(['mail' => true, 'cron' => true]);
});

it('AC-3: steps of a disabled module are left out of the percentage', function () {
    // Langkah Modul sudah selesai sebelumnya agar hanya langkah modul yang dimatikan yang berubah.
    Setting::set('modules.kedisiplinan.enabled', false);
    Classroom::factory()->create();
    Student::factory()->create();
    $before = app(SummarizeSetupProgress::class)->handle();

    Setting::set('modules.akademik.enabled', false);
    Setting::set('modules.keuangan.enabled', false);
    $after = app(SummarizeSetupProgress::class)->handle();

    expect(array_column($after['steps'], 'key'))->not->toContain('classrooms', 'students', 'fee_rates')
        ->and($after['total'])->toBe($before['total'] - 3)
        ->and($after['done_count'])->toBe($before['done_count'] - 2);
});

it('AC-5: reaches 100% when every step is done', function () {
    setupHealth(['mail' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok']);
    Setting::set('school.logo_path', 'school/logo.png');
    Setting::set('school.email', 'tu@pondok.sch.id');
    Setting::set('modules.kedisiplinan.enabled', false);
    Classroom::factory()->create();
    Student::factory()->create();
    FeeRate::factory()->create();
    User::factory()->create()->assignRole('bendahara');

    expect(app(SummarizeSetupProgress::class)->handle())->toMatchArray(['done_count' => 10, 'total' => 10, 'percent' => 100]);
});

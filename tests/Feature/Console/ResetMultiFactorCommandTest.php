<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('AC-8: resets MFA by email from the cli and logs it without a causer', function () {
    $admin = User::factory()->create(['email' => 'admin@pondok.sch.id'])->assignRole('super-admin');
    mfaEnable($admin);

    $exit = Artisan::call('pondok:reset-mfa', ['email' => 'admin@pondok.sch.id']);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('pondok.user.')
        ->and(mfaRaw($admin))->toBe(['secret' => null, 'codes' => null])
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->properties['via'])->toBe('cli');
});

it('AC-8: fails for an unknown email or a user without MFA', function () {
    User::factory()->create(['email' => 'polos@pondok.sch.id']);

    expect(Artisan::call('pondok:reset-mfa', ['email' => 'tidak-ada@pondok.sch.id']))->toBe(1)
        ->and(Artisan::output())->not->toContain('pondok.user.')
        ->and(Artisan::call('pondok:reset-mfa', ['email' => 'polos@pondok.sch.id']))->toBe(1)
        ->and(Artisan::output())->not->toContain('pondok.user.')
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

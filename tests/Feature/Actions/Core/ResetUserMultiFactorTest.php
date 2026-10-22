<?php

use App\Actions\Core\ResetUserMultiFactor;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super-admin');
    $this->target = User::factory()->create()->assignRole('bendahara');
});

it('AC-6: a super-admin resets another user and the log holds no secrets', function () {
    $mfa = mfaEnable($this->target);

    app(ResetUserMultiFactor::class)->handle($this->admin, $this->target);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();
    $logged = json_encode([$activity->properties, $activity->description]);

    expect(mfaRaw($this->target))->toBe(['secret' => null, 'codes' => null])
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($this->target->id)
        ->and($activity->properties['action'])->toBe('reset_multi_factor')
        ->and($logged)->not->toContain($mfa['secret'])
        ->and($logged)->not->toContain($mfa['codes'][0]);
});

it('AC-7: refuses a non super-admin, the user themself, and a user without MFA', function () {
    $mfa = mfaEnable($this->target);
    $custom = User::factory()->create()->givePermissionTo(['view-dashboard', 'view-any:user', 'update:user']);
    $adminMfa = mfaEnable($this->admin);
    $plain = User::factory()->create();

    $attempts = [
        'bendahara' => [$this->target, $this->admin, AuthorizationException::class],
        'peran kustom update:user' => [$custom, $this->target, AuthorizationException::class],
        'diri sendiri' => [$this->admin, $this->admin, ValidationException::class],
        'target tanpa MFA' => [$this->admin, $plain, ValidationException::class],
    ];

    foreach ($attempts as $case => [$actor, $subject, $exception]) {
        expect(fn () => app(ResetUserMultiFactor::class)->handle($actor, $subject))->toThrow($exception, $case);
    }

    expect(mfaRaw($this->target)['secret'])->not->toBeNull()
        ->and(mfaRaw($this->admin)['secret'])->not->toBeNull()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

it('AC-6: a system call without actor resets MFA and logs the cli source without a causer', function () {
    mfaEnable($this->target);

    app(ResetUserMultiFactor::class)->handle(null, $this->target);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

    expect(mfaRaw($this->target))->toBe(['secret' => null, 'codes' => null])
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->properties['via'])->toBe('cli');
});

it('AC-6: translates the reset activity description', function () {
    expectTranslatedKeys(['pondok.user.security.activity_mfa_reset']);
});

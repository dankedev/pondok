<?php

use App\Actions\Core\MarkUserEmailVerified;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super-admin');
});

it('AC-12: a super-admin marks the email verified and the change is logged', function () {
    $target = User::factory()->unverified()->create();

    app(MarkUserEmailVerified::class)->handle($this->admin, $target);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

    expect($target->fresh()->email_verified_at)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($target->id)
        ->and($activity->properties['action'])->toBe('mark_email_verified');
});

it('AC-12: refuses a non super-admin and an already verified user', function () {
    $target = User::factory()->unverified()->create();
    $bendahara = User::factory()->create()->assignRole('bendahara');
    $custom = User::factory()->create()->givePermissionTo(['view-dashboard', 'update:user']);

    expect(fn () => app(MarkUserEmailVerified::class)->handle($bendahara, $target))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkUserEmailVerified::class)->handle($custom, $target))->toThrow(AuthorizationException::class)
        ->and($target->fresh()->email_verified_at)->toBeNull();

    expect(fn () => app(MarkUserEmailVerified::class)->handle($this->admin, User::factory()->create()))->toThrow(ValidationException::class)
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

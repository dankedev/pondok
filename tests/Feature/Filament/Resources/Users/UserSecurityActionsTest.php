<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->create()->assignRole('super-admin');
});

it('AC-9: the reset action asks for confirmation and clears MFA with a success notification', function () {
    $target = User::factory()->create(['name' => 'Bendahara Satu'])->assignRole('bendahara');
    mfaEnable($target);
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->assertActionVisible('resetMultiFactor')
        ->assertActionExists('resetMultiFactor', fn ($action): bool => $action->isConfirmationRequired())
        ->callAction('resetMultiFactor');

    $notifications = asramaNotifications();

    expect(mfaRaw($target))->toBe(['secret' => null, 'codes' => null])
        ->and($notifications->pluck('status')->all())->toContain('success')
        ->and($notifications->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' '))->toContain('Bendahara Satu')
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(1);
});

it('AC-9: hides the reset action for a target without MFA and for the own record', function () {
    $this->actingAs($this->admin);
    mfaEnable($this->admin);

    Livewire::test(EditUser::class, ['record' => User::factory()->create()->id])->assertActionHidden('resetMultiFactor');
    Livewire::test(EditUser::class, ['record' => $this->admin->id])->assertActionHidden('resetMultiFactor');
});

it('AC-9: hides the reset action from a custom role holding update:user', function () {
    $manager = User::factory()->create()->givePermissionTo(['view-dashboard', 'view-any:user', 'view:user', 'update:user']);
    $target = User::factory()->create();
    mfaEnable($target);
    $this->actingAs($manager);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->assertActionHidden('resetMultiFactor')
        ->assertActionHidden('markEmailVerified');
});

it('AC-11: a user created in the panel starts unverified', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Baru', 'email' => 'baru@pondok.sch.id', 'password' => 'kata-sandi-1', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'baru@pondok.sch.id')->sole()->email_verified_at)->toBeNull();
});

it('AC-11: changing the email clears verification, changing only the name does not', function () {
    $target = User::factory()->create(['email' => 'lama@pondok.sch.id']);
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['name' => 'Nama Baru'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->email_verified_at)->not->toBeNull();

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['email' => 'baru@pondok.sch.id'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh())->email->toBe('baru@pondok.sch.id')->email_verified_at->toBeNull();
});

it('AC-11: the email field is disabled when a super-admin edits themself', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->admin->id])
        ->assertFormFieldIsDisabled('email');

    Livewire::test(EditUser::class, ['record' => User::factory()->create()->id])
        ->assertFormFieldIsEnabled('email');
});

it('AC-12: the mark verified action confirms, then verifies and logs', function () {
    $target = User::factory()->unverified()->create();
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->assertActionVisible('markEmailVerified')
        ->assertActionExists('markEmailVerified', fn ($action): bool => $action->isConfirmationRequired())
        ->callAction('markEmailVerified');

    expect($target->fresh()->email_verified_at)->not->toBeNull()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(1);

    Livewire::test(EditUser::class, ['record' => $target->id])->assertActionHidden('markEmailVerified');
});

it('AC-9: translates the security texts', function () {
    expectTranslatedKeys([
        'pondok.user.security.activity_mfa_reset',
        'pondok.user.security.mfa_warning_title',
    ]);
});

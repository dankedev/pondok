<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Filament\Resources\Guardians\Pages\CreateGuardian;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Models\Guardian;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = waliBoot();
    $this->admin = keuanganUser('admin-sekolah');
    $this->guardian = Guardian::factory()->create(['name' => 'Wali Baru']);
});

it('AC-11: the admin action creates a wali user, links it and sends one Laravel reset notification pointing at the wali portal', function () {
    Notification::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
        ->assertActionVisible('createPortalAccount')
        ->callAction('createPortalAccount', ['email' => 'Wali@Contoh.test'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('createPortalAccount');

    $user = User::query()->where('email', 'wali@contoh.test')->firstOrFail();

    expect($user->hasRole('wali'))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->name)->toBe('Wali Baru')
        ->and($this->guardian->fresh()->user_id)->toBe($user->id);

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    $url = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user, &$url): bool {
        $url = $n->toMail($user)->actionUrl;

        return str_starts_with($url, route('portal-wali.password.reset', ['token' => $n->token]));
    });

    expect($url)->toContain('/wali/atur-password/');
    $this->get($url)->assertOk();

    $activity = Activity::query()->where('log_name', 'akademik')->latest('id')->firstOrFail();

    expect($activity->description)->toBe(__('pondok.portal_wali.activity_account_created'))
        ->and($activity->subject_id)->toBe($this->guardian->id)
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and(json_encode($activity->properties))->not->toContain('password')->not->toContain('token');
});

it('AC-12: rejects a used email (any letter case), an invalid email and an already linked guardian', function (string $case) {
    Notification::fake();
    $actor = $this->admin;
    User::factory()->create(['email' => 'dipakai@contoh.test']);
    $users = User::query()->count();
    $linked = $this->f->guardian;

    match ($case) {
        'used' => waliAssertRejected(fn () => app(CreateGuardianPortalAccount::class)->handle($this->guardian, 'dipakai@contoh.test', $actor)),
        'used other case' => waliAssertRejected(fn () => app(CreateGuardianPortalAccount::class)->handle($this->guardian, 'DIPAKAI@Contoh.test', $actor)),
        'invalid' => waliAssertRejected(fn () => app(CreateGuardianPortalAccount::class)->handle($this->guardian, 'bukan-email', $actor)),
        'already linked' => waliAssertRejected(fn () => app(CreateGuardianPortalAccount::class)->handle($linked, 'baru@contoh.test', $actor)),
    };

    expect(User::query()->count())->toBe($users)
        ->and($linked->fresh()->user_id)->toBe($this->f->wali->id);
    Notification::assertNothingSent();
})->with(['used', 'used other case', 'invalid', 'already linked']);

it('AC-12: hides the action for a guardian that already has an account', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    Livewire::test(EditGuardian::class, ['record' => $this->f->guardian->id])->assertActionHidden('createPortalAccount');
});

it('AC-13: the guardian forms have no user_id field and the edit page shows the linked email read-only', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    Livewire::test(CreateGuardian::class)->assertFormFieldDoesNotExist('user_id');
    Livewire::test(EditGuardian::class, ['record' => $this->f->guardian->id])
        ->assertFormFieldDoesNotExist('user_id')
        ->assertSee('wali.satu@contoh.test');
});

it('AC-13: user_id cannot be changed through the form or mass assignment', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
    $intruder = User::factory()->create();

    Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
        ->set('data.user_id', $intruder->id)
        ->call('save');
    Livewire::test(EditGuardian::class, ['record' => $this->f->guardian->id])
        ->set('data.user_id', $intruder->id)
        ->call('save');

    $this->guardian->fill(['user_id' => $intruder->id])->save();

    expect($this->guardian->fresh()->user_id)->toBeNull()
        ->and($this->f->guardian->fresh()->user_id)->toBe($this->f->wali->id);
});

it('AC-14: resetting the password marks the email verified and the wali enters without a verification prompt', function () {
    $user = User::factory()->unverified()->create()->assignRole('wali');
    $token = Password::createToken($user);

    waliReset($user, $token, 'Password-Baru-123!')->assertHasNoErrors();

    $user->refresh();

    expect(Hash::check('Password-Baru-123!', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->get('/wali')->assertOk();
});

it('AC-14: a wrong token leaves the email unverified, a valid one verifies it; a verified user keeps the verification date', function () {
    $unverified = User::factory()->unverified()->create()->assignRole('wali');
    $verified = User::factory()->create(['email_verified_at' => '2024-03-01 08:00:00'])->assignRole('wali');

    waliReset($unverified, 'salah', 'Password-Baru-123!');

    expect($unverified->fresh()->email_verified_at)->toBeNull();

    waliReset($unverified, Password::createToken($unverified), 'Password-Baru-123!');

    waliReset($verified, Password::createToken($verified), 'Password-Baru-123!');

    expect($unverified->fresh()->email_verified_at)->not->toBeNull()
        ->and($verified->fresh()->email_verified_at->toDateTimeString())->toBe('2024-03-01 08:00:00');
});

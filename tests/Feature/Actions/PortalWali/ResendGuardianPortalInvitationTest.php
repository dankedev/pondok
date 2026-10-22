<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Actions\PortalWali\ResendGuardianPortalInvitation;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\GuardianPortalInvitation;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = waliBoot();
    $this->admin = keuanganUser('admin-sekolah');
    $this->guardian = Guardian::factory()->create(['name' => 'Wali Baru']);
});

/**
 * Wali tertunda: akun dibuat lewat Action F081, undangan pertama sudah lewat throttle 60 detik.
 */
function resendPendingGuardian(Guardian $guardian, User $admin, bool $waitThrottle = true): User
{
    $user = app(CreateGuardianPortalAccount::class)->handle($guardian, 'tertunda@contoh.test', $admin);

    if ($waitThrottle) {
        test()->travel(61)->seconds();
    }

    return $user;
}

function resendInvitationTokenRow(): ?object
{
    return DB::table('guardian_invitation_tokens')->where('email', 'tertunda@contoh.test')->first();
}

it('AC-3: sends exactly one fresh invitation, with a single token row and no Laravel reset mail', function () {
    Notification::fake();
    $user = resendPendingGuardian($this->guardian, $this->admin);

    $returned = app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    expect($returned->is($user))->toBeTrue()
        ->and(DB::table('guardian_invitation_tokens')->where('email', 'tertunda@contoh.test')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->where('email', 'tertunda@contoh.test')->exists())->toBeFalse();
    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 2);
    Notification::assertNotSentTo($user, ResetPasswordNotification::class);
});

it('AC-4: the old token stops working and the new token sets the password and verifies the email', function () {
    Notification::fake();
    $user = resendPendingGuardian($this->guardian, $this->admin);
    $oldToken = null;
    Notification::assertSentTo($user, GuardianPortalInvitation::class, function (GuardianPortalInvitation $n) use (&$oldToken): bool {
        $oldToken = $n->token;

        return true;
    });
    $oldHash = $user->password;

    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    waliReset($user, $oldToken, 'Password-Baru-123!', invitation: true)
        ->assertHasErrors(['email' => __('pondok.portal_wali.reset_invalid')]);
    expect($user->fresh()->password)->toBe($oldHash)
        ->and($user->fresh()->email_verified_at)->toBeNull();

    $newToken = null;
    Notification::assertSentTo($user, GuardianPortalInvitation::class, function (GuardianPortalInvitation $n) use (&$newToken, $oldToken): bool {
        if ($n->token !== $oldToken) {
            $newToken = $n->token;
        }

        return true;
    });

    expect($newToken)->not->toBeNull();
    waliReset($user, $newToken, 'Password-Baru-123!', invitation: true)->assertHasNoErrors();

    expect(Hash::check('Password-Baru-123!', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('AC-5: throttles a resend within 60 seconds and leaves token, mail and log untouched, then allows it after 61 seconds', function () {
    Notification::fake();
    $user = resendPendingGuardian($this->guardian, $this->admin, waitThrottle: false);
    $row = resendInvitationTokenRow();
    $logs = Activity::query()->count();

    waliAssertRejected(fn () => app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin));

    try {
        app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);
    } catch (ValidationException $e) {
        expect($e->errors()['email'][0])->toBe(__('pondok.portal_wali.errors.invitation_throttled'));
    }

    $after = resendInvitationTokenRow();
    expect($after->token)->toBe($row->token)
        ->and($after->created_at)->toBe($row->created_at)
        ->and(Activity::query()->count())->toBe($logs);
    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 1);

    $this->travel(61)->seconds();
    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 2);
});

it('AC-7: logs the resend in the akademik log without secrets', function () {
    Notification::fake();
    $user = resendPendingGuardian($this->guardian, $this->admin);

    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    $activities = Activity::query()->where('description', __('pondok.portal_wali.activity_invitation_resent'))->get();

    expect($activities)->toHaveCount(1);
    $activity = $activities->first();

    expect($activity->log_name)->toBe('akademik')
        ->and($activity->subject_id)->toBe($this->guardian->id)
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['action'])->toBe('resend_portal_invitation')
        ->and($activity->properties['user_id'])->toBe($user->id)
        ->and(json_encode($activity->properties))->not->toContain('token')->not->toContain('password');
});

it('AC-8: rejects a guardian without an account, a verified account and a disabled module, with no mail, token or log', function (string $case) {
    Notification::fake();
    $guardian = $this->guardian;
    $message = null;

    match ($case) {
        'no account' => $message = 'no_account',
        'already set password' => (function () use ($guardian, &$message) {
            $user = resendPendingGuardian($guardian, $this->admin);
            $token = Password::broker('guardian_invitations')->createToken($user);
            waliReset($user, $token, 'Password-Baru-123!', invitation: true)->assertHasNoErrors();
            $this->travel(61)->seconds();
            $message = 'already_set_password';
        })(),
        'module off' => (function () use ($guardian, &$message) {
            resendPendingGuardian($guardian, $this->admin);
            Setting::set('modules.portal-wali.enabled', false);
            $message = 'module_disabled';
        })(),
    };

    $mails = Notification::sent(User::query()->where('email', 'tertunda@contoh.test')->first() ?? new User, GuardianPortalInvitation::class)->count();
    $tokens = DB::table('guardian_invitation_tokens')->get()->map(fn ($r) => $r->token.$r->created_at)->all();
    $logs = Activity::query()->count();

    try {
        app(ResendGuardianPortalInvitation::class)->handle($guardian->fresh(), $this->admin);
        test()->fail('ValidationException diharapkan.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('email')
            ->and($e->errors()['email'][0])->toBe(__("pondok.portal_wali.errors.{$message}"));
    }

    expect(DB::table('guardian_invitation_tokens')->get()->map(fn ($r) => $r->token.$r->created_at)->all())->toBe($tokens)
        ->and(Activity::query()->count())->toBe($logs);
    $user = User::query()->where('email', 'tertunda@contoh.test')->first();
    expect($user === null ? 0 : Notification::sent($user, GuardianPortalInvitation::class)->count())->toBe($mails);
})->with(['no account', 'already set password', 'module off']);

it('AC-9: forgot-password counts as having set the password: the action disappears and the Action rejects', function () {
    Notification::fake();
    $user = resendPendingGuardian($this->guardian, $this->admin);
    $token = Password::createToken($user);

    waliReset($user, $token, 'Password-Baru-123!')->assertHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
    Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertActionHidden('resendPortalInvitation');

    waliAssertRejected(fn () => app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin));
});

it('AC-10: keeps the invitation broker config, adds a 60 second throttle and leaves the users broker alone', function () {
    expect(config('auth.passwords.guardian_invitations.throttle'))->toBe(60)
        ->and(config('auth.passwords.guardian_invitations.provider'))->toBe('users')
        ->and(config('auth.passwords.guardian_invitations.table'))->toBe('guardian_invitation_tokens')
        ->and(config('auth.passwords.users.throttle'))->toBe(60);

    Notification::fake();
    $user = app(CreateGuardianPortalAccount::class)->handle($this->guardian, 'baru@contoh.test', $this->admin);

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 1);
});

describe('admin action', function () {
    it('AC-1: shows the resend action for a pending wali account', function () {
        Notification::fake();
        resendPendingGuardian($this->guardian, $this->admin);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
            ->assertActionVisible('resendPortalInvitation');
    });

    it('AC-2: hides the action for a guardian without an account', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertActionHidden('resendPortalInvitation');
    });

    it('AC-2: hides the action for a wali who already set the password', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        $this->f->wali->forceFill(['email_verified_at' => now()])->save();

        Livewire::test(EditGuardian::class, ['record' => $this->f->guardian->id])->assertActionHidden('resendPortalInvitation');
    });

    it('AC-2: hides the action when the portal wali module is off', function () {
        Notification::fake();
        resendPendingGuardian($this->guardian, $this->admin);
        Setting::set('modules.portal-wali.enabled', false);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertActionHidden('resendPortalInvitation');
    });

    it('AC-2: kesantrian (read-only on guardians) cannot open the edit page', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(keuanganUser('kesantrian'));

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertForbidden();
    });

    it('AC-3: the action resends the invitation and shows a success notification with the email', function () {
        Notification::fake();
        $user = resendPendingGuardian($this->guardian, $this->admin);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
            ->callAction('resendPortalInvitation')
            ->assertHasNoActionErrors()
            ->assertNotified(__('pondok.portal_wali.invitation_resent', ['email' => 'tertunda@contoh.test']));

        Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 2);
    });

    it('AC-6: shows a danger notification when throttled and sends nothing more', function () {
        Notification::fake();
        $user = resendPendingGuardian($this->guardian, $this->admin, waitThrottle: false);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
            ->callAction('resendPortalInvitation')
            ->assertNotified(
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title(__('pondok.portal_wali.errors.invitation_throttled'))
            );

        Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 1);
    });
});

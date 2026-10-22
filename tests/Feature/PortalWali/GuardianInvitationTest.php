<?php

use App\Livewire\PortalWali\ForgotPassword as PortalForgotPassword;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const INVITATION_BROKER = 'guardian_invitations';
const NEW_PASSWORD = 'Password-Baru-123!';

beforeEach(function () {
    $this->f = waliBoot();
    $this->invitee = User::factory()->unverified()->create(['email' => 'undangan@contoh.test'])->assignRole('wali');
    $this->oldHash = $this->invitee->password;
});

function invitationAssertUntouched(User $user, string $oldHash): void
{
    $user->refresh();

    expect($user->password)->toBe($oldHash)
        ->and($user->email_verified_at)->toBeNull();
}

describe('F081: invitation token on the portal reset page', function () {
    it('AC-4: accepts an invitation token until 72 hours', function () {
        $token = Password::broker(INVITATION_BROKER)->createToken($this->invitee);
        $this->travelTo(now()->addHours(71)->addMinutes(59));

        waliReset($this->invitee, $token, NEW_PASSWORD, null, true)->assertHasNoErrors()->assertRedirect(route('portal-wali.login'));

        $this->invitee->refresh();

        expect(Hash::check(NEW_PASSWORD, $this->invitee->password))->toBeTrue()
            ->and($this->invitee->email_verified_at)->not->toBeNull();
    });

    it('AC-5: rejects an expired invitation token', function () {
        $token = Password::broker(INVITATION_BROKER)->createToken($this->invitee);
        $this->travelTo(now()->addHours(72)->addMinute());

        waliReset($this->invitee, $token, NEW_PASSWORD, null, true)->assertHasErrors(['email']);

        invitationAssertUntouched($this->invitee, $this->oldHash);
    });

    it('AC-6: rejects an invitation token on the default reset link', function () {
        $token = Password::broker(INVITATION_BROKER)->createToken($this->invitee);

        waliReset($this->invitee, $token, NEW_PASSWORD)->assertHasErrors(['email']);

        invitationAssertUntouched($this->invitee, $this->oldHash);
    });

    it('AC-7: rejects a forgot-password token on the invitation link but keeps the default link working for 60 minutes', function () {
        $token = Password::createToken($this->invitee);

        waliReset($this->invitee, $token, NEW_PASSWORD, null, true)->assertHasErrors(['email']);
        invitationAssertUntouched($this->invitee, $this->oldHash);

        $this->travelTo(now()->addMinutes(61));
        waliReset($this->invitee, $token, NEW_PASSWORD, null, true)->assertHasErrors(['email']);
        waliReset($this->invitee, $token, NEW_PASSWORD)->assertHasErrors(['email']);
        invitationAssertUntouched($this->invitee, $this->oldHash);

        $fresh = Password::createToken($this->invitee);
        $this->travelTo(now()->addMinutes(59));
        waliReset($this->invitee, $fresh, NEW_PASSWORD)->assertHasNoErrors()->assertRedirect(route('portal-wali.login'));
    });

    it('AC-8: clears the token of the other broker after a successful reset', function () {
        $invite = Password::broker(INVITATION_BROKER)->createToken($this->invitee);
        Password::createToken($this->invitee);

        waliReset($this->invitee, $invite, NEW_PASSWORD, null, true)->assertHasNoErrors();

        expect(DB::table('password_reset_tokens')->where('email', $this->invitee->email)->exists())->toBeFalse();

        $other = User::factory()->unverified()->create(['email' => 'lain@contoh.test'])->assignRole('wali');
        $oldInvite = Password::broker(INVITATION_BROKER)->createToken($other);
        $forgot = Password::createToken($other);

        waliReset($other, $forgot, NEW_PASSWORD)->assertHasNoErrors();

        expect(DB::table('guardian_invitation_tokens')->where('email', $other->email)->exists())->toBeFalse();
        waliReset($other, $oldInvite, 'Password-Lain-456!', null, true)->assertHasErrors(['email']);
        expect(Hash::check(NEW_PASSWORD, $other->fresh()->password))->toBeTrue();
    });

    it('AC-9: forgot password keeps a pending invitation', function () {
        Notification::fake();
        $invite = Password::broker(INVITATION_BROKER)->createToken($this->invitee);

        Livewire::test(PortalForgotPassword::class)->set('email', $this->invitee->email)->call('send');

        Notification::assertSentToTimes($this->invitee, ResetPasswordNotification::class, 1);
        expect(Password::broker(INVITATION_BROKER)->tokenExists($this->invitee, $invite))->toBeTrue();

        waliReset($this->invitee, $invite, NEW_PASSWORD, null, true)->assertHasNoErrors();
    });

    it('AC-11: returns 404 when the module is off', function () {
        $token = Password::broker(INVITATION_BROKER)->createToken($this->invitee);
        $url = route('portal-wali.password.reset', ['token' => $token, 'email' => $this->invitee->email, 'undangan' => 1]);

        Setting::set('modules.portal-wali.enabled', false);

        $this->get($url)->assertNotFound();
        waliReset($this->invitee, $token, NEW_PASSWORD, null, true)->assertNotFound();
        invitationAssertUntouched($this->invitee, $this->oldHash);
    });

    it('AC-13: shows one identical error for a wrong token, an unknown email and an expired invitation', function () {
        $token = Password::broker(INVITATION_BROKER)->createToken($this->invitee);
        $expected = __('pondok.portal_wali.reset_invalid');

        $wrong = waliReset($this->invitee, 'token-salah', NEW_PASSWORD, null, true);
        $ghost = User::factory()->make(['email' => 'tidak.ada@contoh.test']);
        $unknownEmail = waliReset($ghost, $token, NEW_PASSWORD, null, true);
        $this->travelTo(now()->addHours(73));
        $expired = waliReset($this->invitee, $token, NEW_PASSWORD, null, true);

        foreach ([$wrong, $unknownEmail, $expired] as $component) {
            $errors = $component->errors()->get('email');

            expect($errors)->toBe([$expected])
                ->and(mb_strtolower($errors[0]))->not->toContain('kata sandi');
        }

        expect($expected)->not->toBe('pondok.portal_wali.reset_invalid');
    });
});

describe('F081: invitation broker configuration', function () {
    it('AC-12: registers a separate invitation broker with its own table', function () {
        expect(config('auth.passwords.guardian_invitations'))->toMatchArray([
            'provider' => 'users',
            'table' => 'guardian_invitation_tokens',
            'expire' => 4320,
        ])->and(config('auth.passwords.users.expire'))->toBe(60)
            ->and(Schema::hasColumns('guardian_invitation_tokens', ['email', 'token', 'created_at']))->toBeTrue();
    });
});

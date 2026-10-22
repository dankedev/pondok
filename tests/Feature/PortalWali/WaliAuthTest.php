<?php

use App\Livewire\PortalWali\ForgotPassword as PortalForgotPassword;
use App\Livewire\PortalWali\Login as PortalLogin;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
});

/**
 * Coba masuk lewat komponen login portal wali.
 */
function waliAttempt(string $email, string $password): Testable
{
    return Livewire::test(PortalLogin::class)->set('email', $email)->set('password', $password)->call('authenticate');
}

describe('AC-4: login', function () {
    it('signs an active wali in, redirects to the home page and regenerates the session id', function () {
        $before = session()->getId();

        waliAttempt($this->f->wali->email, 'password')->assertRedirect(route('portal-wali.home'));

        $this->assertAuthenticatedAs($this->f->wali);
        expect(session()->getId())->not->toBe($before);
    });

    it('rejects every non-eligible account with the same generic message and stays a guest', function (string $case) {
        $user = match ($case) {
            'wrong password' => $this->f->wali,
            'unknown email' => null,
            'staff without wali role' => keuanganUser('bendahara'),
            'staff who is also wali' => User::factory()->create()->assignRole(['bendahara', 'wali']),
            'inactive wali' => $this->f->wali->forceFill(['is_active' => false]),
        };
        $user?->save();
        $email = $user?->email ?? 'tidak.ada@contoh.test';
        $password = $case === 'wrong password' ? 'salah-total' : 'password';

        $component = waliAttempt($email, $password)->assertHasErrors(['email'])->assertNoRedirect();

        expect($component->errors()->first('email'))->toBe(__('auth.failed'));
        $this->assertGuest();
    })->with(['wrong password', 'unknown email', 'staff without wali role', 'staff who is also wali', 'inactive wali']);

    it('shows the login form with the hint that staff use the admin login', function () {
        $this->get('/wali/masuk')->assertOk()->assertSee('Staf pondok masuk lewat halaman admin.');
    });
});

describe('AC-5: login rate limit', function () {
    it('locks the sixth attempt for the same email and IP even with the right password, but not another email', function () {
        foreach (range(1, 5) as $_) {
            waliAttempt($this->f->wali->email, 'salah-total');
        }

        $component = waliAttempt($this->f->wali->email, 'password')->assertHasErrors(['email']);

        expect($component->errors()->first('email'))->toStartWith('Terlalu banyak upaya masuk');
        $this->assertGuest();

        waliAttempt($this->f->other->email, 'password')->assertRedirect(route('portal-wali.home'));
        $this->assertAuthenticatedAs($this->f->other);
    });

    it('clears the failure count after a successful login', function () {
        foreach (range(1, 4) as $_) {
            waliAttempt($this->f->wali->email, 'salah-total');
        }

        waliAttempt($this->f->wali->email, 'password')->assertRedirect(route('portal-wali.home'));
        auth()->logout();

        foreach (range(1, 4) as $_) {
            waliAttempt($this->f->wali->email, 'salah-total');
        }

        waliAttempt($this->f->wali->email, 'password')->assertRedirect(route('portal-wali.home'));
    });
});

describe('AC-6: logout', function () {
    it('signs out with POST, rotates the CSRF token and redirects to the login', function () {
        $this->actingAs($this->f->wali)->withSession(['_token' => 'token-lama'])
            ->post(route('portal-wali.logout'))->assertRedirect(route('portal-wali.login'));

        $this->assertGuest();
        expect(session()->token())->not->toBe('token-lama');
    });

    it('does not sign out on GET', function () {
        $this->actingAs($this->f->wali)->get('/wali/keluar')->assertStatus(405);

        $this->assertAuthenticatedAs($this->f->wali);
    });
});

describe('AC-7: forgot password', function () {
    it('sends one Laravel reset notification with a wali-portal URL to an active wali', function () {
        Notification::fake();

        Livewire::test(PortalForgotPassword::class)->set('email', $this->f->wali->email)->call('send')->assertSee(__('passwords.sent'));

        Notification::assertSentToTimes($this->f->wali, ResetPasswordNotification::class, 1);
        Notification::assertSentTo($this->f->wali, ResetPasswordNotification::class, fn (ResetPasswordNotification $n): bool => str_starts_with(
            $n->toMail($this->f->wali)->actionUrl,
            route('portal-wali.password.reset', ['token' => $n->token]),
        ));
    });

    it('answers the same message and sends nothing to staff, inactive wali or unknown email', function (string $case) {
        Notification::fake();
        $this->f->wali->forceFill(['is_active' => $case !== 'inactive wali'])->save();
        $email = match ($case) {
            'staff' => keuanganUser('bendahara')->email,
            'inactive wali' => $this->f->wali->email,
            'unknown' => 'tidak.ada@contoh.test',
        };

        Livewire::test(PortalForgotPassword::class)->set('email', $email)->call('send')->assertSee(__('passwords.sent'))->assertHasNoErrors();

        Notification::assertNothingSent();
    })->with(['staff', 'inactive wali', 'unknown']);

    it('throttles the sixth request per IP within a minute without sending', function () {
        Notification::fake();
        $walis = collect(range(1, 6))->map(fn () => User::factory()->create()->assignRole('wali'));

        foreach ($walis->take(5) as $wali) {
            Livewire::test(PortalForgotPassword::class)->set('email', $wali->email)->call('send');
        }

        $component = Livewire::test(PortalForgotPassword::class)->set('email', $walis[5]->email)->call('send');

        expect($component->errors()->first('email'))->toStartWith('Terlalu banyak upaya');
        Notification::assertNotSentTo($walis[5], ResetPasswordNotification::class);
    });
});

describe('AC-8: reset password', function () {
    it('changes the password, verifies the email, keeps an existing verification date and redirects to the login', function () {
        $unverified = User::factory()->unverified()->create()->assignRole('wali');
        $verified = User::factory()->create(['email_verified_at' => '2024-03-01 08:00:00'])->assignRole('wali');

        waliReset($unverified, Password::createToken($unverified), 'Password-Baru-123!')->assertRedirect(route('portal-wali.login'));
        waliReset($verified, Password::createToken($verified), 'Password-Baru-123!')->assertRedirect(route('portal-wali.login'));

        expect(Hash::check('Password-Baru-123!', $unverified->fresh()->password))->toBeTrue()
            ->and($unverified->fresh()->email_verified_at)->not->toBeNull()
            ->and($verified->fresh()->email_verified_at->toDateTimeString())->toBe('2024-03-01 08:00:00');
        $this->assertGuest();
    });

    it('rejects a wrong or expired token and a mismatching confirmation, changing nothing', function (string $case) {
        $user = User::factory()->unverified()->create()->assignRole('wali');
        $old = $user->password;
        $token = Password::createToken($user);

        if ($case === 'expired token') {
            $this->travel(61)->minutes();
        }

        $component = match ($case) {
            'wrong token' => waliReset($user, 'token-salah', 'Password-Baru-123!'),
            'expired token' => waliReset($user, $token, 'Password-Baru-123!'),
            'confirmation mismatch' => waliReset($user, $token, 'Password-Baru-123!', 'Beda-Sama-Sekali-1!'),
        };

        $component->assertHasErrors();
        expect($user->fresh()->password)->toBe($old)->and($user->fresh()->email_verified_at)->toBeNull();
    })->with(['wrong token', 'expired token', 'confirmation mismatch']);

    it('invalidates other sessions of the user that were opened with the old password hash', function () {
        $user = $this->f->wali;
        $old = $user->password;

        waliReset($user, Password::createToken($user), 'Password-Baru-123!');

        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $old])
            ->get('/wali')->assertRedirect(route('portal-wali.login'));
        $this->assertGuest();
    });

    it('serves the reset page with Cache-Control no-store', function () {
        $response = $this->get(route('portal-wali.password.reset', ['token' => 'token-apa-saja', 'email' => $this->f->wali->email]))->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('AC-9: admin panel unaffected', function () {
    it('keeps the admin password reset and email verification links under /admin', function () {
        Notification::fake();
        Filament::setCurrentPanel('admin');
        $staff = keuanganUser('bendahara');

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $staff->email])->call('request');

        Notification::assertSentTo($staff, FilamentResetPassword::class, fn (FilamentResetPassword $n): bool => str_contains($n->url, '/admin/') && ! str_contains($n->url, '/wali'));

        $unverified = User::factory()->unverified()->create()->assignRole('super-admin');
        $this->actingAs($unverified);

        Livewire::test(EmailVerificationPrompt::class)->callAction('resendNotification');

        Notification::assertSentTo($unverified, FilamentVerifyEmail::class, fn (FilamentVerifyEmail $n): bool => str_contains($n->url, '/admin/') && ! str_contains($n->url, '/wali'));
    });
});

describe('AC-10: email verification', function () {
    beforeEach(function () {
        $this->f->wali->forceFill(['email_verified_at' => null])->save();
        $this->wali = $this->f->wali->fresh();
    });

    it('redirects an unverified wali from every protected page to the verification prompt and shows a resend button there', function () {
        $this->actingAs($this->wali);
        $notice = route('portal-wali.verification.notice');

        foreach (['/wali', "/wali/santri/{$this->f->s1->id}"] as $path) {
            $this->get($path)->assertRedirect($notice);
        }

        $this->get($notice)->assertOk()->assertSee(route('portal-wali.verification.send'));
    });

    it('sends a signed verification URL that expires after the configured 60 minutes', function () {
        Notification::fake();

        $this->actingAs($this->wali)->post(route('portal-wali.verification.send'))->assertRedirect();

        Notification::assertSentTo($this->wali, VerifyEmail::class, function (VerifyEmail $n): bool {
            $url = $n->toMail($this->wali)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, route('portal-wali.verification.verify', ['id' => $this->wali->id, 'hash' => sha1($this->wali->email)]))
                && (int) $query['expires'] === now()->addMinutes(config('auth.verification.expire', 60))->getTimestamp();
        });
    });

    it('verifies the email through the signed URL and redirects to the home page', function () {
        $url = URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(60), ['id' => $this->wali->id, 'hash' => sha1($this->wali->email)]);

        $this->actingAs($this->wali)->get($url)->assertRedirect(route('portal-wali.home'));

        expect($this->wali->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('rejects an expired URL, a wrong hash, a missing signature and another user id with 403', function (string $case) {
        $other = User::factory()->unverified()->create()->assignRole('wali');
        $parameters = ['id' => $this->wali->id, 'hash' => sha1($this->wali->email)];

        $url = match ($case) {
            'expired' => tap(URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(60), $parameters), fn () => $this->travel(61)->minutes()),
            'wrong hash' => URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(60), [...$parameters, 'hash' => sha1('lain@contoh.test')]),
            'unsigned' => route('portal-wali.verification.verify', $parameters),
            'other user' => URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(60), ['id' => $other->id, 'hash' => sha1($other->email)]),
        };

        $this->actingAs($this->wali)->get($url)->assertForbidden();

        expect($this->wali->fresh()->hasVerifiedEmail())->toBeFalse()->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
    })->with(['expired', 'wrong hash', 'unsigned', 'other user']);

    it('throttles the seventh resend within a minute with 429', function () {
        Notification::fake();
        $this->actingAs($this->wali);

        foreach (range(1, 6) as $_) {
            $this->post(route('portal-wali.verification.send'))->assertRedirect();
        }

        $this->post(route('portal-wali.verification.send'))->assertStatus(429);
    });
});

<?php

use App\Enums\Module;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Livewire\PortalWali\ForgotPassword as PortalForgotPassword;
use App\Livewire\PortalWali\Login as PortalLogin;
use App\Livewire\PortalWali\ResetPassword as PortalResetPassword;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
});

describe('VERIFY F079: forgot password', function () {
    it('creates no token row for staff or inactive wali and leaves the admin reset token untouched', function () {
        Notification::fake();
        $staff = keuanganUser('bendahara');
        $adminToken = Password::createToken($staff);
        $storedHash = DB::table('password_reset_tokens')->where('email', $staff->email)->value('token');
        $this->f->wali->forceFill(['is_active' => false])->save();

        foreach ([$staff->email, $this->f->wali->email] as $email) {
            Livewire::test(PortalForgotPassword::class)->set('email', $email)->call('send');
        }

        expect(DB::table('password_reset_tokens')->where('email', $staff->email)->value('token'))->toBe($storedHash)
            ->and(DB::table('password_reset_tokens')->where('email', $this->f->wali->email)->exists())->toBeFalse()
            ->and(Password::tokenExists($staff, $adminToken))->toBeTrue();
        Notification::assertNothingSent();
    });

    it('gives the identical status for a throttled broker request as for a fresh one', function () {
        Notification::fake();
        $first = Livewire::test(PortalForgotPassword::class)->set('email', $this->f->wali->email)->call('send');
        $second = Livewire::test(PortalForgotPassword::class)->set('email', $this->f->wali->email)->call('send');

        expect($second->get('status'))->toBe($first->get('status'));
        $second->assertHasNoErrors();
        Notification::assertSentToTimes($this->f->wali, ResetPasswordNotification::class, 1);
    });
});

describe('VERIFY F079: reset password', function () {
    it('resets another account while signed in as someone else without touching or switching the session', function () {
        $x = $this->f->wali;
        $victim = $this->f->other;
        $xHash = $x->password;
        $token = Password::createToken($victim);

        $this->actingAs($x)->get(route('portal-wali.password.reset', ['token' => $token, 'email' => $victim->email]))->assertOk();

        waliReset($victim, $token, 'Password-Baru-123!')->assertRedirect(route('portal-wali.login'));

        $this->assertAuthenticatedAs($x);
        expect($x->fresh()->password)->toBe($xHash)
            ->and(Hash::check('Password-Baru-123!', $victim->fresh()->password))->toBeTrue();
    });

    it('cannot reuse a token issued for one email to reset another email', function () {
        $token = Password::createToken($this->f->other);
        $old = $this->f->wali->password;

        waliReset($this->f->wali, $token, 'Password-Baru-123!')->assertHasErrors(['email']);

        expect($this->f->wali->fresh()->password)->toBe($old);
    });

    it('keeps the token locked against client tampering', function () {
        $token = Password::createToken($this->f->other);

        Livewire::test(PortalResetPassword::class, ['token' => 'token-salah'])
            ->set('token', $token);
    })->throws(Exception::class);

    it('does not log in or grant extra access when a staff admin token is used on the portal reset page', function () {
        $staff = keuanganUser('bendahara');
        $token = Password::createToken($staff);

        waliReset($staff, $token, 'Password-Baru-123!')->assertRedirect(route('portal-wali.login'));

        $this->assertGuest();
        $this->actingAs($staff->fresh())->get('/wali')->assertForbidden();
        expect($staff->fresh()->hasRole('wali'))->toBeFalse();
    });
});

describe('VERIFY F079: login', function () {
    it('shares the rate limit counter between case variants of the same email', function () {
        foreach (range(1, 5) as $_) {
            advLoginAttempt($this->f->wali->email, 'salah-total');
        }

        $component = advLoginAttempt(strtoupper($this->f->wali->email), 'password');

        expect($component->errors()->first('email'))->toStartWith('Terlalu banyak upaya masuk');
        $this->assertGuest();
    });

    it('does not let a staff session turn into a wali session through the portal login of a staff+wali account', function () {
        $dual = User::factory()->create()->assignRole(['bendahara', 'wali']);

        advLoginAttempt($dual->email, 'password')->assertHasErrors(['email']);

        $this->assertGuest();
    });
});

describe('VERIFY F079: email verification', function () {
    it('rejects a staff user opening a verification link and does not verify them', function () {
        $staff = User::factory()->unverified()->create()->assignRole('bendahara');
        $url = URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(60), ['id' => $staff->id, 'hash' => sha1($staff->email)]);

        $this->actingAs($staff)->get($url)->assertForbidden();

        expect($staff->fresh()->email_verified_at)->toBeNull();
    });
});

describe('VERIFY F079: data pages', function () {
    it('escapes HTML in student names on the home, student and invoice pages', function () {
        $this->f->s1->forceFill(['name' => '<script>alert(1)</script>Santri'])->save();
        $invoice = waliInvoice($this->f, $this->f->s1);

        foreach (['/wali', "/wali/santri/{$this->f->s1->id}", "/wali/tagihan/{$invoice->id}"] as $path) {
            $html = $this->actingAs($this->f->wali)->get($path)->assertOk()->getContent();
            expect($html)->not->toContain('<script>alert(1)</script>')->and($html)->toContain('&lt;script&gt;alert(1)');
        }
    });

    it('escapes HTML in the guardian name shown in the account bar', function () {
        $this->f->guardian->forceFill(['name' => '<img src=x onerror=alert(1)>'])->save();

        expect($this->actingAs($this->f->wali)->get('/wali')->assertOk()->getContent())->not->toContain('<img src=x onerror');
    });

    it('sends no-store privacy headers on pages with personal data', function () {
        $invoice = waliInvoice($this->f, $this->f->s1);

        foreach (['/wali', "/wali/santri/{$this->f->s1->id}", "/wali/tagihan/{$invoice->id}"] as $path) {
            $response = $this->actingAs($this->f->wali)->get($path)->assertOk();
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
        }
    });
});

describe('VERIFY F079: module switch on custom routes', function () {
    it('answers 403 or 404 (never a redirect or error) to guests and super-admins on every module-guarded route when all modules are off', function () {
        foreach (Module::cases() as $module) {
            Setting::set("modules.{$module->value}.enabled", false);
        }
        $admin = kontenUser('super-admin');

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => in_array('GET', $route->methods(), true)
            && collect($route->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_starts_with($m, EnsureModuleIsEnabled::class)));

        expect($routes->count())->toBeGreaterThan(10);

        foreach ($routes as $route) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            foreach ([null, $admin] as $user) {
                $status = ($user ? $this->actingAs($user) : $this->withoutMiddleware([]))->get($uri)->getStatusCode();
                expect($status)->toBeIn([403, 404], ($user ? 'admin' : 'tamu')." {$uri} => {$status}");
            }
            auth()->logout();
        }
    });
});

describe('VERIFY F079: livewire with module off', function () {
    it('returns 404 for every portal livewire action after the module is switched off', function () {
        $login = Livewire::test(PortalLogin::class)->set('email', $this->f->wali->email)->set('password', 'password');
        $forgot = Livewire::test(PortalForgotPassword::class)->set('email', $this->f->wali->email);
        Setting::set('modules.portal-wali.enabled', false);

        $login->call('authenticate')->assertNotFound();
        $forgot->call('send')->assertNotFound();
        $this->assertGuest();
    });
});

/**
 * Coba masuk lewat komponen login portal wali.
 */
function advLoginAttempt(string $email, string $password): Testable
{
    return Livewire::test(PortalLogin::class)->set('email', $email)->set('password', $password)->call('authenticate');
}

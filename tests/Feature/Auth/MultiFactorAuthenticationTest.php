<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->user = User::factory()->create(['email' => 'admin@pondok.sch.id'])->assignRole('super-admin');
});

/**
 * Login sampai tantangan MFA dimunculkan.
 */
function mfaLoginToChallenge(): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@pondok.sch.id', 'password' => 'password'])
        ->call('authenticate')
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);
}

it('AC-2: stores the secret encrypted and the recovery codes hashed, and hides both from arrays', function () {
    $mfa = mfaEnable($this->user);
    $raw = mfaRaw($this->user);
    $hashes = json_decode(Crypt::decryptString($raw['codes']), true);

    expect($raw['secret'])->not->toContain($mfa['secret'])
        ->and(Crypt::decryptString($raw['secret']))->toBe($mfa['secret'])
        ->and($hashes)->toHaveCount(count($mfa['codes']))
        ->and($raw['codes'])->not->toContain($mfa['codes'][0])
        ->and(Hash::check($mfa['codes'][0], $hashes[0]))->toBeTrue()
        ->and($this->user->fresh()->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes']);
});

it('AC-3: logs in directly without MFA and asks for a challenge with MFA', function () {
    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@pondok.sch.id', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
    auth()->logout();

    mfaEnable($this->user);

    mfaLoginToChallenge();

    $this->assertGuest();
});

it('AC-3: rejects a wrong code and accepts the current TOTP code', function () {
    mfaEnable($this->user);
    $current = AppAuthentication::make()->getCurrentCode($this->user->fresh());
    $wrong = $current === '123456' ? '654321' : '123456';

    mfaLoginToChallenge()
        ->set('data.multiFactor.code', $wrong)
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.code']);

    $this->assertGuest();

    mfaLoginToChallenge()
        ->set('data.multiFactor.code', $current)
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
});

it('AC-4: a recovery code logs in once and is then consumed', function () {
    $mfa = mfaEnable($this->user);
    $code = $mfa['codes'][0];

    mfaLoginToChallenge()
        ->set('data.multiFactor.useRecoveryCode', true)
        ->set('data.multiFactor.recoveryCode', $code)
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(count($mfa['codes']) - 1);
    auth()->logout();

    mfaLoginToChallenge()
        ->set('data.multiFactor.useRecoveryCode', true)
        ->set('data.multiFactor.recoveryCode', $code)
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.recoveryCode']);

    $this->assertGuest();
});

it('AC-4: the profile page manages the authenticator app', function () {
    $this->actingAs($this->user);

    Livewire::test(EditProfile::class)->assertSee('Aplikasi authenticator');
});

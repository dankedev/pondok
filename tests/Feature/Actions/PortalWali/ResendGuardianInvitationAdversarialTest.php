<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Actions\PortalWali\ResendGuardianPortalInvitation;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\GuardianPortalInvitation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    $this->f = waliBoot();
    $this->admin = keuanganUser('admin-sekolah');
    $this->guardian = Guardian::factory()->create();
    Notification::fake();
});

function advPending(Guardian $guardian, User $admin, string $email = 'adv@contoh.test'): User
{
    return app(CreateGuardianPortalAccount::class)->handle($guardian, $email, $admin);
}

it('throttle boundary: still rejected at 59 seconds, allowed at exactly 60 seconds', function () {
    $user = advPending($this->guardian, $this->admin);

    $this->travel(59)->seconds();
    waliAssertRejected(fn () => app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin));

    $this->travel(1)->seconds();
    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 2);
});

it('back-to-back resends: the second call right after a successful resend is throttled', function () {
    $user = advPending($this->guardian, $this->admin);
    $this->travel(61)->seconds();

    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);
    waliAssertRejected(fn () => app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin));

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 2);
});

it('throttle does not leak between guardians: a fresh invite for guardian A does not block guardian B', function () {
    $a = advPending($this->guardian, $this->admin, 'a@contoh.test');
    $this->travel(61)->seconds();
    $guardianB = Guardian::factory()->create();
    $b = advPending($guardianB, $this->admin, 'b@contoh.test');
    $this->travel(61)->seconds();

    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);
    // A baru saja dikirim ulang; B sudah lewat 60 detik sejak undangan terakhirnya? Tidak: B dibuat sebelum travel kedua.
    app(ResendGuardianPortalInvitation::class)->handle($guardianB->fresh(), $this->admin);

    Notification::assertSentToTimes($a, GuardianPortalInvitation::class, 2);
    Notification::assertSentToTimes($b, GuardianPortalInvitation::class, 2);
});

it('a resend leaves other guardians invitation tokens untouched', function () {
    advPending($this->guardian, $this->admin, 'a@contoh.test');
    $other = advPending(Guardian::factory()->create(), $this->admin, 'b@contoh.test');
    $before = DB::table('guardian_invitation_tokens')->where('email', $other->email)->first();
    $this->travel(61)->seconds();

    app(ResendGuardianPortalInvitation::class)->handle($this->guardian->fresh(), $this->admin);

    $after = DB::table('guardian_invitation_tokens')->where('email', $other->email)->first();
    expect($after->token)->toBe($before->token)
        ->and(DB::table('guardian_invitation_tokens')->count())->toBe(2);
});

it('calling the hidden action directly on a wali who already set the password sends nothing', function () {
    $user = advPending($this->guardian, $this->admin);
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->travel(61)->seconds();
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $lw = Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])
        ->assertActionHidden('resendPortalInvitation');
    expect(fn () => $lw->callAction('resendPortalInvitation'))->toThrow(AssertionFailedError::class);

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 1);
});

it('calling the hidden action directly while the portal wali module is off sends nothing', function () {
    $user = advPending($this->guardian, $this->admin);
    $this->travel(61)->seconds();
    Setting::set('modules.portal-wali.enabled', false);
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $lw = Livewire::test(EditGuardian::class, ['record' => $this->guardian->id]);
    expect(fn () => $lw->callAction('resendPortalInvitation'))->toThrow(AssertionFailedError::class);

    Notification::assertSentToTimes($user, GuardianPortalInvitation::class, 1);
});

it('roles without guardian update permission cannot resend: no access to the edit page', function (string $role) {
    advPending($this->guardian, $this->admin);
    $this->travel(61)->seconds();
    Filament::setCurrentPanel('admin');
    $this->actingAs(keuanganUser($role));

    Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertForbidden();
})->with(['kesantrian', 'bendahara', 'admin-psb', 'musyrif', 'wali']);

it('a guardian whose linked user was deleted is rejected as no_account and the action is hidden', function () {
    $user = advPending($this->guardian, $this->admin);
    $this->travel(61)->seconds();
    $user->delete();
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    waliAssertRejected(fn () => app(ResendGuardianPortalInvitation::class)->handle(Guardian::query()->find($this->guardian->id), $this->admin));
    Livewire::test(EditGuardian::class, ['record' => $this->guardian->id])->assertActionHidden('resendPortalInvitation');
});

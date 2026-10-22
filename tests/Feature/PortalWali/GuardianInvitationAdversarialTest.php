<?php

use App\Livewire\PortalWali\ResetPassword as PortalResetPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
    $this->invitee = User::factory()->unverified()->create(['email' => 'undangan@contoh.test'])->assignRole('wali');
    $this->oldHash = $this->invitee->password;
});

describe('F081 VERIFY: celah broker undangan', function () {
    it('locks the invitation flag after mount so a client cannot switch brokers', function () {
        $token = Password::broker('guardian_invitations')->createToken($this->invitee);

        Livewire::withQueryParams([]);
        $component = Livewire::test(PortalResetPassword::class, ['token' => $token]);

        expect(fn () => $component->set('invitation', true))->toThrow('Cannot update locked property: [invitation]');
    });

    it('treats undangan=0 as the default broker', function () {
        $invite = Password::broker('guardian_invitations')->createToken($this->invitee);

        Livewire::withQueryParams(['undangan' => 0]);
        Livewire::test(PortalResetPassword::class, ['token' => $invite])
            ->set('email', $this->invitee->email)->set('password', 'Password-Baru-123!')->set('password_confirmation', 'Password-Baru-123!')
            ->call('resetPassword')->assertHasErrors(['email']);

        expect($this->invitee->fresh()->password)->toBe($this->oldHash);
    });

    it('does not let a staff forgot-password token work on the invitation link', function () {
        $staff = User::factory()->create()->assignRole('bendahara');
        $oldStaffHash = $staff->password;
        $token = Password::createToken($staff);

        waliReset($staff, $token, 'Password-Baru-123!', null, true)->assertHasErrors(['email']);

        expect($staff->fresh()->password)->toBe($oldStaffHash)
            ->and(Password::tokenExists($staff, $token))->toBeTrue();
    });

    it('keeps the other broker token when the reset fails', function () {
        $invite = Password::broker('guardian_invitations')->createToken($this->invitee);
        $forgot = Password::createToken($this->invitee);

        waliReset($this->invitee, 'token-salah', 'Password-Baru-123!', null, true)->assertHasErrors(['email']);
        waliReset($this->invitee, $invite, 'Password-Baru-123!', 'Beda-Konfirmasi-1!', true)->assertHasErrors(['password']);
        waliReset($this->invitee, $forgot, 'pendek', null, true)->assertHasErrors(['password']);

        expect(Password::tokenExists($this->invitee, $forgot))->toBeTrue()
            ->and(Password::broker('guardian_invitations')->tokenExists($this->invitee, $invite))->toBeTrue()
            ->and($this->invitee->fresh()->password)->toBe($this->oldHash);
    });

    it('makes an invitation token single use', function () {
        $invite = Password::broker('guardian_invitations')->createToken($this->invitee);

        waliReset($this->invitee, $invite, 'Password-Baru-123!', null, true)->assertHasNoErrors();
        waliReset($this->invitee, $invite, 'Password-Lain-456!', null, true)->assertHasErrors(['email']);

        expect(Hash::check('Password-Baru-123!', $this->invitee->fresh()->password))->toBeTrue();
    });

    it('lets a newer invitation token replace the older one for the same email', function () {
        $first = Password::broker('guardian_invitations')->createToken($this->invitee);
        $second = Password::broker('guardian_invitations')->createToken($this->invitee);

        expect(DB::table('guardian_invitation_tokens')->where('email', $this->invitee->email)->count())->toBe(1);

        waliReset($this->invitee, $first, 'Password-Baru-123!', null, true)->assertHasErrors(['email']);
        waliReset($this->invitee, $second, 'Password-Baru-123!', null, true)->assertHasNoErrors();
    });

    it('rejects the invitation token of one wali used with the email of another', function () {
        $token = Password::broker('guardian_invitations')->createToken($this->invitee);
        $victim = $this->f->wali;
        $victimHash = $victim->password;

        waliReset($victim, $token, 'Password-Baru-123!', null, true)->assertHasErrors(['email']);

        expect($victim->fresh()->password)->toBe($victimHash);
    });
});

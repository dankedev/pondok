<?php

namespace App\Actions\PortalWali;

use App\Enums\Module;
use App\Models\Guardian;
use App\Models\User;
use App\Notifications\GuardianPortalInvitation;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Mengirim ulang undangan akun portal wali (F084) untuk wali yang punya akun tetapi belum
 * mengatur password (`email_verified_at` kosong). Token baru menggugurkan token lama,
 * dibatasi throttle broker undangan (60 detik), dan dicatat di activity log sesudah terkirim.
 */
class ResendGuardianPortalInvitation
{
    public function handle(Guardian $guardian, User $actor): User
    {
        if (! Module::PortalWali->isEnabled()) {
            throw $this->reject('module_disabled');
        }

        $user = $guardian->user;

        if ($user === null) {
            throw $this->reject('no_account');
        }

        if ($user->hasVerifiedEmail()) {
            throw $this->reject('already_set_password');
        }

        if (Password::broker(GuardianPortalInvitation::BROKER)->getRepository()->recentlyCreatedToken($user)) {
            throw $this->reject('invitation_throttled');
        }

        GuardianPortalInvitation::sendTo($user);

        activity('akademik')
            ->causedBy($actor)
            ->performedOn($guardian)
            ->event('updated')
            ->withProperties(['action' => 'resend_portal_invitation', 'user_id' => $user->id])
            ->log(__('pondok.portal_wali.activity_invitation_resent'));

        return $user;
    }

    private function reject(string $error): ValidationException
    {
        return ValidationException::withMessages(['email' => __("pondok.portal_wali.errors.{$error}")]);
    }
}

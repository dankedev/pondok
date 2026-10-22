<?php

namespace App\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Reset password lewat tautan email membuktikan kepemilikan email, jadi email yang
 * belum terverifikasi ditandai terverifikasi (F022: akun wali dibuat admin tanpa verifikasi).
 */
class MarkEmailVerifiedOnPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof MustVerifyEmail && ! $event->user->hasVerifiedEmail()) {
            $event->user->markEmailAsVerified();
        }
    }
}

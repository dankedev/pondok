<?php

namespace App\Actions\Core;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menandai email pengguna terverifikasi tanpa email (F055), jalan keluar bila SMTP
 * belum diatur atau gagal. Hanya super-admin.
 */
class MarkUserEmailVerified
{
    public function handle(User $actor, User $target): void
    {
        if (! $actor->hasRole('super-admin')) {
            throw new AuthorizationException(__('pondok.user.security.errors.forbidden'));
        }

        if ($target->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'user' => __('pondok.user.security.errors.already_verified'),
            ]);
        }

        DB::transaction(function () use ($actor, $target): void {
            $target->markEmailAsVerified();

            activity('pengaturan')
                ->causedBy($actor)
                ->performedOn($target)
                ->event('updated')
                ->withProperties(['action' => 'mark_email_verified'])
                ->log(__('pondok.user.security.activity_email_verified', ['name' => $target->name]));
        });
    }
}

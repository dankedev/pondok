<?php

namespace App\Actions\Core;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menonaktifkan MFA aplikasi milik pengguna lain (F055): secret dan kode pemulihan
 * dikosongkan. Dari panel hanya super-admin dan bukan untuk dirinya sendiri; tanpa
 * actor berarti dipanggil operator server lewat `pondok:reset-mfa`.
 */
class ResetUserMultiFactor
{
    public function handle(?User $actor, User $target): void
    {
        if ($actor !== null) {
            if (! $actor->hasRole('super-admin')) {
                throw new AuthorizationException(__('pondok.user.security.errors.forbidden'));
            }

            if ($actor->is($target)) {
                throw ValidationException::withMessages([
                    'user' => __('pondok.user.security.errors.reset_self'),
                ]);
            }
        }

        if (blank($target->getAppAuthenticationSecret())) {
            throw ValidationException::withMessages([
                'user' => __('pondok.user.security.errors.mfa_not_enabled'),
            ]);
        }

        DB::transaction(function () use ($actor, $target): void {
            $target->forceFill([
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
            ])->save();

            $log = activity('pengaturan');

            if ($actor !== null) {
                $log->causedBy($actor);
            }

            $log->performedOn($target)
                ->event('updated')
                ->withProperties(['action' => 'reset_multi_factor', 'via' => $actor === null ? 'cli' : 'panel'])
                ->log(__('pondok.user.security.activity_mfa_reset', ['name' => $target->name]));
        });
    }
}

<?php

namespace App\Actions\PortalWali;

use App\Enums\Module;
use App\Models\Guardian;
use App\Models\User;
use App\Notifications\GuardianPortalInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Membuat akun portal untuk wali (F022): user baru berperan `wali`, ditautkan lewat
 * `guardians.user_id` (satu-satunya pengisi kolom itu), lalu email undangan portal wali (F081)
 * berisi tautan atur password dari broker undangan (berlaku 72 jam) dikirim ke email tersebut.
 * Email belum terverifikasi sampai tautan dibuka. Email dikirim di luar transaksi: bila pengiriman
 * gagal, akun tetap dibuat dan wali memakai "Lupa password".
 */
class CreateGuardianPortalAccount
{
    public function handle(Guardian $guardian, string $email, User $actor): User
    {
        $email = Str::lower(trim($email));

        if (! Module::PortalWali->isEnabled()) {
            throw $this->reject('module_disabled');
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            throw $this->reject('invalid_email');
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw $this->reject('email_taken');
        }

        try {
            $user = DB::transaction(function () use ($guardian, $email, $actor): User {
                $locked = Guardian::query()->lockForUpdate()->findOrFail($guardian->id);

                if ($locked->user_id !== null) {
                    throw $this->reject('already_linked');
                }

                $user = User::query()->forceCreate([
                    'name' => $locked->name,
                    'email' => $email,
                    'password' => Str::password(40),
                    'is_active' => true,
                ]);
                $user->assignRole('wali');

                $locked->forceFill(['user_id' => $user->id])->save();

                activity('akademik')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->event('updated')
                    ->withProperties(['action' => 'create_portal_account', 'user_id' => $user->id])
                    ->log(__('pondok.portal_wali.activity_account_created'));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->reject('email_taken');
        }

        $guardian->forceFill(['user_id' => $user->id])->syncOriginal();

        $this->sendPasswordSetupLink($user);

        return $user;
    }

    private function sendPasswordSetupLink(User $user): void
    {
        $token = Password::broker(GuardianPortalInvitation::BROKER)->createToken($user);

        $user->notify(new GuardianPortalInvitation($token));
    }

    private function reject(string $error): ValidationException
    {
        return ValidationException::withMessages(['email' => __("pondok.portal_wali.errors.{$error}")]);
    }
}

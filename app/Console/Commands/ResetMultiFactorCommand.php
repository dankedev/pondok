<?php

namespace App\Console\Commands;

use App\Actions\Core\ResetUserMultiFactor;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Reset MFA lewat server, untuk pengguna (termasuk super-admin tunggal) yang
 * kehilangan HP dan kode pemulihan. Exit 1 bila email tidak dikenal atau tanpa MFA.
 */
#[Signature('pondok:reset-mfa {email : Email akun yang MFA-nya direset}')]
#[Description('Menonaktifkan 2FA sebuah akun panel')]
class ResetMultiFactorCommand extends Command
{
    public function handle(ResetUserMultiFactor $reset): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(__('pondok.user.reset_mfa_command.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        try {
            $reset->handle(null, $user);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('pondok.user.reset_mfa_command.done', ['email' => $email]));

        return self::SUCCESS;
    }
}

<?php

namespace App\Actions\Core;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Membuat akun super-admin, atau memberi peran super-admin ke akun yang
 * emailnya sudah ada tanpa mengubah nama dan passwordnya. Akun itu dijamin
 * terverifikasi emailnya (F055).
 */
class CreateSuperAdmin
{
    /**
     * @return array{user: User, created: bool}
     */
    public function handle(string $name, string $email, string $password): array
    {
        $this->validate($name, $email, $password);

        if (! Role::query()->where('name', RolePermissionSeeder::SUPER_ADMIN)->where('guard_name', 'web')->exists()) {
            throw new RuntimeException(__('pondok.install.super_admin_role_missing'));
        }

        $user = User::query()->where('email', $email)->first();
        $created = $user === null;

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();
        }

        // Jalan keluar bila SMTP belum bisa mengirim email verifikasi.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        if (! $user->hasRole(RolePermissionSeeder::SUPER_ADMIN)) {
            $user->assignRole(RolePermissionSeeder::SUPER_ADMIN);
        }

        return ['user' => $user, 'created' => $created];
    }

    /**
     * Validasi kredensial tanpa menyentuh database (dipakai `pondok:install`
     * sebelum migrasi).
     */
    public function validate(string $name, string $email, string $password): void
    {
        Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            ],
            [],
            [
                'name' => __('pondok.install.attributes.name'),
                'email' => __('pondok.install.attributes.email'),
                'password' => __('pondok.install.attributes.password'),
            ],
        )->validate();
    }
}

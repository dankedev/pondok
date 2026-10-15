<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('pondok.super_admin');

        if (blank($config['email']) || blank($config['password'])) {
            throw new RuntimeException(
                'SUPER_ADMIN_EMAIL dan SUPER_ADMIN_PASSWORD wajib diisi di .env sebelum menjalankan seeder.'
            );
        }

        $user = User::query()->firstOrCreate(
            ['email' => $config['email']],
            [
                'name' => $config['name'],
                'password' => $config['password'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->assignRole(RolePermissionSeeder::SUPER_ADMIN);
    }
}

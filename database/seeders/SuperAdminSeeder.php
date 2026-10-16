<?php

namespace Database\Seeders;

use App\Actions\Core\CreateSuperAdmin;
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

        app(CreateSuperAdmin::class)->handle((string) ($config['name'] ?: 'Super Admin'), $config['email'], $config['password']);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data sistem yang aman di-seed ulang saat `pondok:upgrade`: permission dan
 * setting baru. Setting yang sudah ada tidak ditimpa; permission peran bawaan
 * dikembalikan ke definisi aplikasi.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }
}

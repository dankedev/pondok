<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data awal instalasi tanpa akun pengguna (dipakai `pondok:install`). Semua
 * seeder memakai firstOrCreate sehingga aman dijalankan ulang.
 */
class InstallSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            AcademicYearSeeder::class,
            SettingSeeder::class,
            RolePermissionSeeder::class,
            DocumentTypeSeeder::class,
            AdmissionTrackSeeder::class,
            StaticPageSeeder::class,
            ContentCategorySeeder::class,
        ]);
    }
}

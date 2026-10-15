<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            AcademicYearSeeder::class,
            SettingSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            DocumentTypeSeeder::class,
            AdmissionTrackSeeder::class,
        ]);
    }
}

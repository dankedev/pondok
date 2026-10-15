<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Modul selain inti. Diaktifkan satu per satu saat modulnya selesai dibangun.
     */
    public const MODULES = [
        'akademik', 'asrama', 'psb', 'keuangan', 'konten',
        'dokumen', 'prestasi', 'tahfidz', 'perizinan',
    ];

    /** Modul yang sudah selesai dibangun dan aktif saat instalasi baru. */
    public const ENABLED_BY_DEFAULT = ['akademik', 'asrama'];

    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa nilai yang sudah diubah dari panel.
        Setting::query()->firstOrCreate(['key' => 'school.name'], ['value' => config('app.name')]);

        foreach (self::MODULES as $module) {
            Setting::query()->firstOrCreate(['key' => "modules.{$module}.enabled"], ['value' => in_array($module, self::ENABLED_BY_DEFAULT, true)]);
        }
    }
}

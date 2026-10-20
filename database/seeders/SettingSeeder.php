<?php

namespace Database\Seeders;

use App\Actions\Akademik\GenerateStudentNumber;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateReceiptNumber;
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
    public const ENABLED_BY_DEFAULT = ['akademik', 'asrama', 'psb', 'keuangan', 'dokumen', 'konten', 'prestasi'];

    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa nilai yang sudah diubah dari panel.
        Setting::query()->firstOrCreate(['key' => 'school.name'], ['value' => config('app.name')]);

        foreach (self::MODULES as $module) {
            Setting::query()->firstOrCreate(['key' => "modules.{$module}.enabled"], ['value' => in_array($module, self::ENABLED_BY_DEFAULT, true)]);
        }

        // Format NIS otomatis (F012). Syarat bayar daftar ulang ada di `units` (F040).
        Setting::query()->firstOrCreate(['key' => GenerateStudentNumber::SETTING_KEY], ['value' => GenerateStudentNumber::DEFAULT_FORMAT]);
        Setting::query()->firstOrCreate(['key' => GenerateInvoiceNumber::SETTING_KEY], ['value' => GenerateInvoiceNumber::DEFAULT_FORMAT]);
        Setting::query()->firstOrCreate(['key' => GenerateReceiptNumber::SETTING_KEY], ['value' => GenerateReceiptNumber::DEFAULT_FORMAT]);
    }
}

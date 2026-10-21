<?php

namespace Database\Seeders;

use App\Actions\Akademik\GenerateStudentNumber;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateReceiptNumber;
use App\Enums\Module;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa nilai yang sudah diubah dari panel.
        Setting::query()->firstOrCreate(['key' => 'school.name'], ['value' => config('app.name')]);

        // Semua modul fitur aktif saat instalasi baru; dimatikan dari Pengaturan → Modul.
        foreach (Module::cases() as $module) {
            Setting::query()->firstOrCreate(['key' => "modules.{$module->value}.enabled"], ['value' => true]);
        }

        // Format NIS otomatis (F012). Syarat bayar daftar ulang ada di `units` (F040).
        Setting::query()->firstOrCreate(['key' => GenerateStudentNumber::SETTING_KEY], ['value' => GenerateStudentNumber::DEFAULT_FORMAT]);
        Setting::query()->firstOrCreate(['key' => GenerateInvoiceNumber::SETTING_KEY], ['value' => GenerateInvoiceNumber::DEFAULT_FORMAT]);
        Setting::query()->firstOrCreate(['key' => GenerateReceiptNumber::SETTING_KEY], ['value' => GenerateReceiptNumber::DEFAULT_FORMAT]);
    }
}

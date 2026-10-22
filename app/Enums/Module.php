<?php

namespace App\Enums;

use App\Models\Setting;

/**
 * Modul fitur yang bisa dinyalakan/dimatikan dari Pengaturan → Modul (F066).
 * Nilai = key saklar `modules.<nilai>.enabled`. Modul inti tidak ada di sini.
 * Fitur baru wajib menambah case di sini beserta `requires()`-nya.
 */
enum Module: string
{
    case Akademik = 'akademik';
    case Asrama = 'asrama';
    case Keuangan = 'keuangan';
    case Psb = 'psb';
    case Dokumen = 'dokumen';
    case Konten = 'konten';
    case Prestasi = 'prestasi';
    case PortalWali = 'portal-wali';
    case Tahfidz = 'tahfidz';
    case Kesehatan = 'kesehatan';

    public function label(): string
    {
        return __("pondok.modules.{$this->value}.label");
    }

    public function description(): string
    {
        return __("pondok.modules.{$this->value}.description");
    }

    /**
     * Modul yang harus aktif sebelum modul ini bisa diaktifkan.
     *
     * @return list<Module>
     */
    public function requires(): array
    {
        return match ($this) {
            self::Asrama, self::Keuangan, self::Prestasi, self::PortalWali, self::Tahfidz, self::Kesehatan => [self::Akademik],
            self::Psb => [self::Akademik, self::Keuangan],
            self::Akademik, self::Dokumen, self::Konten => [],
        };
    }

    /**
     * Modul yang membutuhkan modul ini, diturunkan dari `requires()` semua case.
     *
     * @return list<Module>
     */
    public function dependents(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (Module $module): bool => in_array($this, $module->requires(), true),
        ));
    }

    /**
     * Label beberapa modul dipisah koma, mis. "Akademik, Keuangan".
     *
     * @param  array<Module>  $modules
     */
    public static function labels(array $modules): string
    {
        return implode(', ', array_map(fn (Module $module): string => $module->label(), $modules));
    }

    public function isEnabled(): bool
    {
        return Setting::moduleEnabled($this->value);
    }
}

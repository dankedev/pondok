<?php

namespace App\Enums;

use App\Actions\Core\CreateBackup;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Frekuensi backup terjadwal (F085), disimpan di `backup.frequency`.
 * Kosong atau tidak dikenal = harian, agar instance lama tetap seperti F042.
 * Jumlah arsip sama untuk semua pilihan (`CreateBackup::KEEP`).
 */
enum BackupFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public static function current(): self
    {
        return self::tryFrom((string) Setting::get('backup.frequency')) ?? self::Daily;
    }

    public function label(): string
    {
        return __("pondok.backup_frequency.{$this->value}.label");
    }

    public function description(): string
    {
        return __("pondok.backup_frequency.{$this->value}.description", ['keep' => CreateBackup::KEEP]);
    }

    /**
     * Mingguan berjalan setiap Ahad, bulanan setiap tanggal 1.
     */
    public function isDueOn(CarbonInterface $at): bool
    {
        return match ($this) {
            self::Daily => true,
            self::Weekly => $at->isSunday(),
            self::Monthly => $at->day === 1,
        };
    }
}

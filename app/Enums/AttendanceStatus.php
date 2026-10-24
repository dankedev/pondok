<?php

namespace App\Enums;

/**
 * Status kehadiran santri pada absensi harian rombel (F025).
 */
enum AttendanceStatus: string
{
    case Present = 'hadir';
    case Sick = 'sakit';
    case Excused = 'izin';
    case Absent = 'alpa';

    public function label(): string
    {
        return __("pondok.attendance.statuses.{$this->value}");
    }

    /**
     * Warna badge sesuai design-guide §4 Absensi.
     */
    public function color(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Sick => 'warning',
            self::Excused => 'info',
            self::Absent => 'danger',
        };
    }
}

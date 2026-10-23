<?php

namespace App\Enums;

/**
 * Status izin santri (F024). Transisi hanya lewat Action `App\Actions\Perizinan\*`:
 * diajukan → disetujui | ditolak | batal, disetujui → keluar | batal, keluar → kembali.
 * "Terlambat" tidak disimpan sebagai status (`LeaveRequest::scopeLate`).
 */
enum LeaveRequestStatus: string
{
    case Submitted = 'diajukan';
    case Approved = 'disetujui';
    case Rejected = 'ditolak';
    case Out = 'keluar';
    case Returned = 'kembali';
    case Cancelled = 'batal';

    /** Status yang menandakan izin masih berjalan; santri tidak boleh punya dua sekaligus. */
    public const ONGOING = [self::Submitted, self::Approved, self::Out];

    public function label(): string
    {
        return __("pondok.leave_request.statuses.{$this->value}");
    }

    /**
     * Warna badge sesuai design-guide §4 Perizinan.
     */
    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Approved, self::Returned => 'success',
            self::Rejected => 'danger',
            self::Out => 'info',
            self::Cancelled => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function ongoingValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::ONGOING);
    }
}

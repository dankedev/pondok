<?php

namespace App\Enums;

/**
 * Asal permintaan izin (F024 D11), bukan siapa yang mengetik.
 */
enum LeaveRequestVia: string
{
    case Guardian = 'wali';
    case Student = 'santri';
    case Musyrif = 'musyrif';

    public function label(): string
    {
        return __("pondok.leave_request.via.{$this->value}");
    }
}

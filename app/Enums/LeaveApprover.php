<?php

namespace App\Enums;

/**
 * Penyetuju sebuah jenis izin (`leave_types.approver`, F024). Musyrif dan kepala asrama dicari dari
 * `dormitory_supervisors` santri (`ResolveLeaveApprover`); kesantrian = pemegang `approve-any:leave-request`.
 */
enum LeaveApprover: string
{
    case Musyrif = 'musyrif';
    case HeadOfDormitory = 'kepala_asrama';
    case Kesantrian = 'kesantrian';

    public function label(): string
    {
        return __("pondok.leave_type.approvers.{$this->value}");
    }
}

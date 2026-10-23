<?php

namespace Database\Seeders;

use App\Enums\LeaveApprover;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Jenis izin bawaan saat instalasi (F024 D10); bisa disesuaikan dari panel.
 */
class LeaveTypeSeeder extends Seeder
{
    public const TYPES = [
        ['code' => 'KELUAR', 'max_hours' => 6, 'approver' => LeaveApprover::Musyrif, 'requires_guardian' => false],
        ['code' => LeaveType::CODE_HOMECOMING, 'max_hours' => null, 'approver' => LeaveApprover::HeadOfDormitory, 'requires_guardian' => true],
        ['code' => 'SAKIT', 'max_hours' => null, 'approver' => LeaveApprover::Kesantrian, 'requires_guardian' => false],
        ['code' => 'KEPERLUAN', 'max_hours' => 72, 'approver' => LeaveApprover::Kesantrian, 'requires_guardian' => true],
    ];

    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa jenis izin yang sudah diubah.
        foreach (self::TYPES as $type) {
            LeaveType::query()->firstOrCreate(['code' => $type['code']], [
                'name' => __("pondok.leave_type.defaults.{$type['code']}"),
                'is_active' => true,
                ...$type,
            ]);
        }
    }
}

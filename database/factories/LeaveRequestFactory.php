<?php

namespace Database\Factories;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveRequestVia;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'leave_type_id' => LeaveType::factory(),
            'homecoming_period_id' => null,
            'requested_via' => LeaveRequestVia::Musyrif,
            'requested_by' => null,
            'reason' => 'Keperluan keluarga',
            'planned_departure' => '2026-10-15 13:00',
            'return_due' => '2026-10-15 17:00',
            'status' => LeaveRequestStatus::Submitted,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\LeaveApprover;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('IZN###')),
            'name' => 'Izin '.fake()->word(),
            'max_hours' => 6,
            'approver' => LeaveApprover::Musyrif,
            'requires_guardian' => false,
            'is_active' => true,
        ];
    }
}

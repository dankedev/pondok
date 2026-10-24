<?php

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\Classroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'classroom_id' => Classroom::factory(),
            'held_on' => '2026-10-15',
            'recorded_by' => null,
        ];
    }
}

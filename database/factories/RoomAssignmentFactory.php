<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomAssignment>
 */
class RoomAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'room_id' => Room::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'start_on' => '2026-07-15',
            'end_on' => null,
            'note' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(['start_on' => '2025-07-15', 'end_on' => '2026-06-30']);
    }
}

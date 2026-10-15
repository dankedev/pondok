<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DormitorySupervisor>
 */
class DormitorySupervisorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dormitory_id' => Dormitory::factory(),
            'room_id' => null,
            'staff_id' => Staff::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'role' => DormitorySupervisor::ROLE_SUPERVISOR,
            'start_on' => '2026-07-15',
            'end_on' => null,
        ];
    }

    public function head(): static
    {
        return $this->state(['role' => DormitorySupervisor::ROLE_HEAD, 'room_id' => null]);
    }

    public function forRoom(Room $room): static
    {
        return $this->state(['dormitory_id' => $room->dormitory_id, 'room_id' => $room->id]);
    }

    public function ended(): static
    {
        return $this->state(['start_on' => '2025-07-15', 'end_on' => '2026-06-30']);
    }
}

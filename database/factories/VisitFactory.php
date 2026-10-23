<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'guardian_id' => null,
            'visitor_name' => fake()->name(),
            'relation' => 'ayah',
            'started_at' => '2026-10-15 09:00',
            'ended_at' => null,
            'items_note' => null,
            'recorded_by' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\HealthVisit;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthVisit>
 */
class HealthVisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'visited_at' => '2026-10-14 16:30',
            'complaint' => 'Demam dan pusing',
            'treatment' => 'Istirahat dan parasetamol',
            'note' => null,
            'needs_referral' => false,
            'needs_leave' => false,
            'recorded_by' => null,
        ];
    }
}

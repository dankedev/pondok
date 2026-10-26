<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\DiniyahReportNote;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiniyahReportNote>
 */
class DiniyahReportNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'semester' => 1,
            'note' => fake()->sentence(),
            'recorded_by' => null,
        ];
    }
}

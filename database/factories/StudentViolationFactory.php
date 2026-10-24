<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\ViolationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentViolation>
 */
class StudentViolationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'violation_type_id' => ViolationType::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'occurred_on' => '2026-10-14',
            'points' => 5,
            'description' => null,
            'recorded_by' => null,
        ];
    }
}

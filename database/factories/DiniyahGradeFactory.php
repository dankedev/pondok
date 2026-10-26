<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\DiniyahGrade;
use App\Models\DiniyahSubject;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiniyahGrade>
 */
class DiniyahGradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'semester' => 1,
            'diniyah_subject_id' => DiniyahSubject::factory(),
            'score' => fake()->numberBetween(0, 100),
            'recorded_by' => null,
        ];
    }
}

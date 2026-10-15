<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'classroom_id' => Classroom::factory(),
            'academic_year_id' => fn (array $attributes): int => Classroom::query()->findOrFail($attributes['classroom_id'])->academic_year_id,
        ];
    }
}

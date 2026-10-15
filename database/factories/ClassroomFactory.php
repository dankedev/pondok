<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 6);

        return [
            'academic_year_id' => AcademicYear::factory(),
            'unit_id' => Unit::factory(),
            'grade' => $grade,
            'name' => $grade.fake()->unique()->bothify('?#'),
            'gender' => Classroom::GENDER_MIXED,
            'capacity' => null,
        ];
    }
}

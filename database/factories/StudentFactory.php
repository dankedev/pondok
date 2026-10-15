<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            // Jenjang selalu mengikuti angkatan.
            'unit_id' => fn (array $attributes): int => Cohort::query()->findOrFail($attributes['cohort_id'])->unit_id,
            'nis' => fake()->unique()->numerify('##########'),
            'nisn' => fake()->unique()->numerify('##########'),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-6 years')->format('Y-m-d'),
            'address' => fake()->address(),
            'status' => Student::STATUS_ACTIVE,
            'entered_on' => fake()->dateTimeBetween('-3 years')->format('Y-m-d'),
        ];
    }

    public function forCohort(Cohort $cohort): static
    {
        return $this->state(['cohort_id' => $cohort->id, 'unit_id' => $cohort->unit_id]);
    }
}

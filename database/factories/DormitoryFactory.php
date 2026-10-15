<?php

namespace Database\Factories;

use App\Models\Dormitory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dormitory>
 */
class DormitoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ASR-####'),
            'name' => 'Asrama '.fake()->unique()->lastName(),
            'gender' => fake()->randomElement(Dormitory::GENDERS),
            'location' => 'Blok '.fake()->randomLetter(),
            'is_active' => true,
        ];
    }

    public function male(): static
    {
        return $this->state(['gender' => 'L']);
    }

    public function female(): static
    {
        return $this->state(['gender' => 'P']);
    }
}

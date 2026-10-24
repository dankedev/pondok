<?php

namespace Database\Factories;

use App\Models\DisciplineLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisciplineLevel>
 */
class DisciplineLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Peringatan '.fake()->unique()->numberBetween(1, 99),
            'min_points' => fake()->unique()->numberBetween(1, 1000),
        ];
    }
}

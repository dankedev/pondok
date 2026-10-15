<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('U???')),
            'name' => fake()->words(2, true),
            'sort_order' => fake()->numberBetween(1, 9),
        ];
    }
}

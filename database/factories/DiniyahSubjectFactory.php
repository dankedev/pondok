<?php

namespace Database\Factories;

use App\Models\DiniyahSubject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiniyahSubject>
 */
class DiniyahSubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 1,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

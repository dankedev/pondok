<?php

namespace Database\Factories;

use App\Enums\ViolationCategory;
use App\Models\ViolationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ViolationType>
 */
class ViolationTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'category' => ViolationCategory::Light,
            'points' => 5,
            'is_active' => true,
        ];
    }
}

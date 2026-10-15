<?php

namespace Database\Factories;

use App\Models\FeeOverride;
use App\Models\Scholarship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Program beasiswa aktif dengan potongan persen.
 *
 * @extends Factory<Scholarship>
 */
class ScholarshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Beasiswa '.fake()->unique()->words(3, true),
            'mode' => FeeOverride::MODE_PERCENT,
            'default_value' => fake()->randomElement([25, 50, 75, 100]),
            'sponsor' => null,
            'is_active' => true,
        ];
    }

    public function nominal(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_NOMINAL, 'default_value' => $value]);
    }

    public function discount(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_DISCOUNT, 'default_value' => $value]);
    }

    public function percent(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_PERCENT, 'default_value' => $value]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

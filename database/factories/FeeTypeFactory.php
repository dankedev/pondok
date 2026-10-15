<?php

namespace Database\Factories;

use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeType>
 */
class FeeTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z]{4}_[0-9]{4}'),
            'name' => 'Biaya '.fake()->unique()->word(),
            'frequency' => fake()->randomElement(FeeType::FREQUENCIES),
            'applies_to' => FeeType::APPLIES_TO_ALL,
            'billing_group' => 'BULANAN',
            'priority' => FeeType::DEFAULT_PRIORITY,
            'is_active' => true,
        ];
    }

    public function monthly(): static
    {
        return $this->state(['frequency' => FeeType::FREQUENCY_MONTHLY]);
    }

    public function resident(): static
    {
        return $this->state(['applies_to' => FeeType::APPLIES_TO_RESIDENT]);
    }

    public function nonResident(): static
    {
        return $this->state(['applies_to' => FeeType::APPLIES_TO_NON_RESIDENT]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cohort>
 */
class CohortFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2000, 2099);

        return [
            'unit_id' => Unit::factory(),
            'entry_year' => $year,
            'name' => "Angkatan {$year}",
        ];
    }
}

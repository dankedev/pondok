<?php

namespace Database\Factories;

use App\Models\AdmissionTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Jalur aktif tanpa beasiswa.
 *
 * @extends Factory<AdmissionTrack>
 */
class AdmissionTrackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('JALUR_[A-Z]{4}[0-9]{3}'),
            'name' => 'Jalur '.fake()->unique()->word(),
            'scholarship_id' => null,
            'terms' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

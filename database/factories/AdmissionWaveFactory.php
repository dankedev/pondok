<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Gelombang terbuka tanpa batas kuota. Langsung ke database, tanpa
 * pemeriksaan irisan tanggal milik `SaveAdmissionWave`.
 *
 * @extends Factory<AdmissionWave>
 */
class AdmissionWaveFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'Gelombang '.fake()->unique()->numberBetween(1, 99999),
            'opens_on' => '2027-01-01',
            'closes_on' => '2027-03-31',
            'quota' => null,
            'is_open' => true,
        ];
    }

    public function closed(): static
    {
        return $this->state(['is_open' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tarif default jenjang. Kolom generated `*_key` tidak pernah ditulis.
 *
 * @extends Factory<FeeRate>
 */
class FeeRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_type_id' => FeeType::factory(),
            'effective_from_year_id' => AcademicYear::factory(),
            'unit_id' => Unit::factory(),
            'cohort_id' => null,
            'dormitory_id' => null,
            'admission_wave_id' => null,
            'amount' => fake()->numberBetween(10, 1000) * 1000,
        ];
    }

    public function forCohort(Cohort $cohort): static
    {
        return $this->state(['cohort_id' => $cohort->id, 'unit_id' => $cohort->unit_id]);
    }

    public function forDormitory(Dormitory $dormitory): static
    {
        return $this->state(['dormitory_id' => $dormitory->id]);
    }

    public function forWave(int $waveId): static
    {
        return $this->state(['admission_wave_id' => $waveId]);
    }
}

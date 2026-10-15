<?php

namespace Database\Factories;

use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Keringanan potongan rupiah untuk santri aktif, berlaku mulai awal bulan ini tanpa akhir.
 *
 * @extends Factory<FeeOverride>
 */
class FeeOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'applicant_id' => null,
            'fee_type_id' => FeeType::factory(),
            'scholarship_id' => null,
            'mode' => FeeOverride::MODE_DISCOUNT,
            'value' => fake()->numberBetween(10, 200) * 1000,
            'reason' => fake()->sentence(4),
            'valid_from' => today()->startOfMonth()->toDateString(),
            'valid_until' => null,
            'approved_by' => User::factory(),
        ];
    }

    public function nominal(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_NOMINAL, 'value' => $value]);
    }

    public function free(): static
    {
        return $this->nominal(0);
    }

    public function discount(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_DISCOUNT, 'value' => $value]);
    }

    public function percent(int $value): static
    {
        return $this->state(['mode' => FeeOverride::MODE_PERCENT, 'value' => $value]);
    }

    public function between(string $from, ?string $until): static
    {
        return $this->state(['valid_from' => $from, 'valid_until' => $until]);
    }

    public function forApplicant(int $applicantId): static
    {
        return $this->state(['student_id' => null, 'applicant_id' => $applicantId]);
    }

    public function forScholarship(Scholarship $scholarship): static
    {
        return $this->state(['scholarship_id' => $scholarship->id]);
    }
}

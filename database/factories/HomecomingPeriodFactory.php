<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\HomecomingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomecomingPeriod>
 */
class HomecomingPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'Libur Semester Ganjil',
            'depart_on' => '2026-12-20',
            'return_on' => '2027-01-03',
        ];
    }
}

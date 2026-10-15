<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nip' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(['L', 'P']),
            'phone' => fake()->phoneNumber(),
            'position' => fake()->randomElement(['ustadz', 'musyrif', 'TU']),
            'is_active' => true,
        ];
    }
}

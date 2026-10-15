<?php

namespace Database\Factories;

use App\Models\Dormitory;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dormitory_id' => Dormitory::factory(),
            'name' => 'Kamar '.fake()->unique()->numberBetween(100, 99999),
            'floor' => fake()->numberBetween(1, 3),
            'capacity' => 10,
            'is_active' => true,
        ];
    }
}

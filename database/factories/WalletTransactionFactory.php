<?php

namespace Database\Factories;

use App\Enums\WalletTransactionType;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Setoran hari ini. Factory tidak memeriksa saldo; test perilaku memakai `RecordWalletTransaction`.
 *
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => WalletTransactionType::Setor,
            'amount' => fake()->numberBetween(1, 100) * 1000,
            'occurred_on' => today()->toDateString(),
            'note' => null,
            'status' => WalletTransaction::STATUS_SUCCESS,
            'recorded_by' => User::factory(),
        ];
    }

    public function withdrawal(): static
    {
        return $this->state(['type' => WalletTransactionType::Tarik]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => WalletTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
            'cancel_reason' => 'Salah catat',
        ]);
    }
}

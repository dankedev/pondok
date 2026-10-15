<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pembayaran tunai hari ini yang diterima petugas. Factory tidak memperbarui
 * `paid_amount`/`paid_total`; test perilaku memakai `RecordPayment`.
 *
 * Nomor kuitansi memakai urutan 50000-89999 agar tidak bentrok dengan nomor
 * yang diterbitkan `GenerateReceiptNumber` di test.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_no' => fn (): string => sprintf('KW-%s-%05d', today()->format('Ym'), fake()->unique()->numberBetween(50000, 89999)),
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->numberBetween(10, 500) * 1000,
            'paid_on' => today()->toDateString(),
            'method' => Payment::METHOD_CASH,
            'proof_path' => null,
            'gateway_ref' => null,
            'received_by' => User::factory(),
            'note' => null,
            'status' => Payment::STATUS_RECEIVED,
        ];
    }

    public function transfer(): static
    {
        return $this->state(['method' => Payment::METHOD_TRANSFER]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => Payment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
            'cancel_reason' => 'Salah input',
        ]);
    }
}

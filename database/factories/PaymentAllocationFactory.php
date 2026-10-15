<?php

namespace Database\Factories;

use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_item_id' => InvoiceItem::factory(),
            'amount' => fake()->numberBetween(10, 500) * 1000,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Item tarif jenjang tanpa keringanan; `amount` selalu `base_amount - discount_amount`.
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'fee_type_id' => FeeType::factory(),
            'description' => fn (array $attributes): string => FeeType::query()->find($attributes['fee_type_id'])?->name ?? 'Biaya',
            'base_amount' => fake()->numberBetween(50, 800) * 1000,
            'discount_amount' => 0,
            'amount' => fn (array $attributes): int => $attributes['base_amount'] - $attributes['discount_amount'],
            'rate_source' => InvoiceItem::RATE_SOURCE_UNIT,
            'fee_override_id' => null,
            'paid_amount' => 0,
        ];
    }
}

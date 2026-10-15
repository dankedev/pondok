<?php

namespace App\Actions\Keuangan;

use App\Models\Cohort;
use App\Models\FeeRate;
use App\Models\FeeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah tarif.
 *
 * Nominal bilangan bulat rupiah >= 0. Satu kombinasi jenis biaya, tahun
 * berlaku, jenjang, angkatan, asrama, dan gelombang hanya boleh punya satu
 * tarif (unique `fee_rates_combination_unique` tetap pengaman terakhir).
 * `admission_wave_id` belum divalidasi keberadaannya sampai F009.
 */
class SaveFeeRate
{
    /**
     * @param  array{fee_type_id: int|string, effective_from_year_id: int|string, unit_id: int|string, cohort_id?: int|string|null, dormitory_id?: int|string|null, admission_wave_id?: int|string|null, amount: mixed}  $data
     */
    public function handle(array $data, ?FeeRate $feeRate = null): FeeRate
    {
        $amount = $this->amount($data['amount'] ?? null);

        if ($amount === null) {
            $this->fail('amount', 'amount_invalid');
        }

        $attributes = [
            'fee_type_id' => (int) $data['fee_type_id'],
            'effective_from_year_id' => (int) $data['effective_from_year_id'],
            'unit_id' => (int) $data['unit_id'],
            'cohort_id' => $this->nullableId($data['cohort_id'] ?? null),
            'dormitory_id' => $this->nullableId($data['dormitory_id'] ?? null),
            'admission_wave_id' => $this->nullableId($data['admission_wave_id'] ?? null),
        ];

        if (
            $attributes['cohort_id'] !== null
            && (int) Cohort::query()->whereKey($attributes['cohort_id'])->value('unit_id') !== $attributes['unit_id']
        ) {
            $this->fail('cohort_id', 'cohort_unit_mismatch');
        }

        if (
            $attributes['dormitory_id'] !== null
            && FeeType::query()->whereKey($attributes['fee_type_id'])->value('applies_to') === FeeType::APPLIES_TO_NON_RESIDENT
        ) {
            $this->fail('dormitory_id', 'dormitory_rate_for_nonresident_fee');
        }

        return DB::transaction(function () use ($attributes, $amount, $feeRate): FeeRate {
            $duplicate = FeeRate::query()
                ->when($feeRate, fn ($query, FeeRate $feeRate) => $query->whereKeyNot($feeRate->getKey()))
                ->where(function ($query) use ($attributes): void {
                    foreach ($attributes as $column => $value) {
                        $value === null ? $query->whereNull($column) : $query->where($column, $value);
                    }
                })
                ->exists();

            if ($duplicate) {
                $this->fail('effective_from_year_id', 'duplicate_rate');
            }

            $feeRate ??= new FeeRate;
            $feeRate->fill([...$attributes, 'amount' => $amount])->save();

            return $feeRate;
        });
    }

    /**
     * Bilangan bulat >= 0 (int atau string berisi digit saja); selain itu null.
     */
    private function amount(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function nullableId(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.keuangan.errors.{$reason}"),
        ]);
    }
}

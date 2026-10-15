<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tagihan `BULANAN` belum bayar untuk santri aktif, periode bulan ini.
 *
 * Nomor tagihan memakai urutan 10000-49999 agar tidak bentrok antar
 * pemanggilan factory dan tetap menyisakan ruang untuk nomor generator.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_no' => fn (array $attributes): string => sprintf(
                'INV-%s-%05d',
                CarbonImmutable::parse($attributes['period'])->format('Ym'),
                fake()->unique()->numberBetween(10000, 49999),
            ),
            'student_id' => Student::factory(),
            'applicant_id' => null,
            'academic_year_id' => AcademicYear::factory(),
            'period' => today()->startOfMonth()->toImmutable(),
            'billing_group' => 'BULANAN',
            'total' => fake()->numberBetween(100, 1500) * 1000,
            'paid_total' => 0,
            'status' => Invoice::STATUS_UNPAID,
            'due_on' => fn (array $attributes): string => CarbonImmutable::parse($attributes['period'])->setDay(10)->toDateString(),
            'generated_by' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(['status' => Invoice::STATUS_CANCELLED]);
    }

    /**
     * Lunas sebesar `total` yang sudah terpasang; pasang `total` lebih dulu.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'paid_total' => $attributes['total'],
            'status' => Invoice::STATUS_PAID,
        ]);
    }

    public function partiallyPaid(int $amount): static
    {
        return $this->state(['paid_total' => $amount, 'status' => Invoice::STATUS_PARTIAL]);
    }
}

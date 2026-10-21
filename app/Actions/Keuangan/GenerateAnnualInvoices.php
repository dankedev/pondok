<?php

namespace App\Actions\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membuat tagihan jenis biaya tahunan untuk semua santri aktif, sekali per
 * santri per tahun ajaran, pada bulan `keuangan.annual_billing_month`.
 *
 * Periode tagihan adalah tanggal 1 bulan itu di dalam tahun ajaran. Aturan
 * tarif, keringanan, penggabungan kelompok, dan lock sama dengan generator
 * bulanan (`GenerateMonthlyInvoices::generateFor()`). Semua error validasi
 * dilaporkan pada field `academic_year_id`.
 */
class GenerateAnnualInvoices
{
    public const MONTH_SETTING_KEY = 'keuangan.annual_billing_month';

    public function __construct(private GenerateMonthlyInvoices $generator) {}

    /**
     * Bulan tagihan tahunan (1-12), atau null bila belum diatur atau tidak valid.
     */
    public static function billingMonth(): ?int
    {
        $month = Setting::get(self::MONTH_SETTING_KEY);

        return is_numeric($month) && (int) $month == $month && $month >= 1 && $month <= 12 ? (int) $month : null;
    }

    /**
     * @return array{period: string, created_invoices: int, added_items: int, existing_invoices: int, skipped: list<array{student_id: int, fee_type_id: int, reason: string}>}
     *
     * @throws ValidationException bulan belum diatur, di luar tahun ajaran, atau periode sedang diproses
     * @throws RuntimeException nomor tagihan terus bentrok setelah beberapa percobaan
     */
    public function handle(AcademicYear $academicYear, ?User $generatedBy = null): array
    {
        $month = self::billingMonth() ?? $this->fail(__('pondok.invoice.errors.annual_month_not_set'));
        $period = $this->periodFor($academicYear, $month) ?? $this->fail(__('pondok.invoice.errors.annual_month_outside_year'));

        try {
            return $this->generator->generateFor($period, $academicYear, FeeType::FREQUENCY_YEARLY, $generatedBy);
        } catch (ValidationException $exception) {
            $this->fail(...$exception->validator->errors()->all());
        }
    }

    /**
     * Tanggal 1 bulan pertama berangka `$month` di dalam rentang tahun ajaran.
     */
    private function periodFor(AcademicYear $academicYear, int $month): ?CarbonImmutable
    {
        $period = CarbonImmutable::parse($academicYear->starts_on->format('Y-m-d'), 'Asia/Jakarta')->startOfMonth();
        $endsOn = $academicYear->ends_on->format('Y-m-d');

        while ($period->toDateString() <= $endsOn) {
            if ($period->month === $month) {
                return $period;
            }

            $period = $period->addMonth();
        }

        return null;
    }

    private function fail(string ...$messages): never
    {
        throw ValidationException::withMessages(['academic_year_id' => $messages]);
    }
}

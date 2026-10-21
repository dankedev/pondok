<?php

namespace Database\Seeders\Demo;

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\GenerateAnnualInvoices;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\RecordPayment;
use App\Actions\Keuangan\SaveFeeOverride;
use App\Actions\Keuangan\SaveFeeRate;
use App\Actions\Keuangan\SaveFeeType;
use App\Actions\Keuangan\SaveScholarship;
use App\Actions\Psb\SaveAdmissionTrack;
use App\Models\AdmissionTrack;
use App\Models\Cohort;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scholarship;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Jenis biaya, tarif, bulan tagihan tahunan, beasiswa, keringanan, jalur PSB
 * berbeasiswa, tagihan bulanan, dan pola pembayaran demo.
 */
final class DemoFinance
{
    public const CORRECTION_SHORTFALL = 50000;

    public const CORRECTION_REASON = 'Salah input nominal';

    /** @var array<string, FeeType> */
    private array $feeTypes = [];

    /** @var array<string, Scholarship> kunci program (yatim/tahfidz/ustadz) => program */
    private array $scholarships = [];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $this->seedFeeTypes();
        $this->seedRates();
        $this->seedAnnualBillingMonth();
        $this->seedScholarships();
        $this->seedStudentOverrides();
        $this->seedTracks();
        $this->generateInvoices();
        $this->seedPayments();
    }

    private function seedFeeTypes(): void
    {
        foreach (DemoData::FEE_TYPES as $row) {
            $this->feeTypes[$row['code']] = FeeType::query()->where('code', $row['code'])->first()
                ?? app(SaveFeeType::class)->handle([...$row, 'is_active' => true]);
        }
    }

    /**
     * @param  array{fee_type_id: int, effective_from_year_id: int, unit_id: int, cohort_id?: int|null, dormitory_id?: int|null, admission_wave_id?: int|null, amount: int}  $data
     */
    private function saveRate(array $data): void
    {
        $exists = FeeRate::query()
            ->where(function ($query) use ($data): void {
                foreach (['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_id', 'dormitory_id', 'admission_wave_id'] as $column) {
                    $value = $data[$column] ?? null;
                    $value === null ? $query->whereNull($column) : $query->where($column, $value);
                }
            })
            ->exists();

        if (! $exists) {
            app(SaveFeeRate::class)->handle($data);
        }
    }

    private function seedRates(): void
    {
        $previousYearId = $this->context->previousYear->id;

        foreach (DemoData::BASE_RATES as $code => $amount) {
            foreach (array_keys(DemoData::FIRST_GRADE) as $unitCode) {
                $this->saveRate([
                    'fee_type_id' => $this->feeTypes[$code]->id,
                    'effective_from_year_id' => $previousYearId,
                    'unit_id' => $this->context->unit($unitCode)->id,
                    'amount' => $amount,
                ]);
            }
        }

        $cohortUnit = $this->context->unit(DemoData::COHORT_RATE['unit']);
        $cohort = Cohort::query()
            ->where('unit_id', $cohortUnit->id)
            ->where('entry_year', $this->context->calendar->entryYear(DemoData::COHORT_RATE['entry_offset']))
            ->firstOrFail();
        $this->saveRate([
            'fee_type_id' => $this->feeTypes[DemoData::COHORT_RATE['fee']]->id,
            'effective_from_year_id' => $previousYearId,
            'unit_id' => $cohortUnit->id,
            'cohort_id' => $cohort->id,
            'amount' => DemoData::COHORT_RATE['amount'],
        ]);
    }

    /**
     * Bulan tagihan tahunan = bulan mulai tahun ajaran aktif, hanya bila belum pernah diatur
     * (termasuk sengaja dikosongkan).
     */
    private function seedAnnualBillingMonth(): void
    {
        if (Setting::query()->whereKey(GenerateAnnualInvoices::MONTH_SETTING_KEY)->doesntExist()) {
            Setting::set(GenerateAnnualInvoices::MONTH_SETTING_KEY, $this->context->activeYear->starts_on->month);
        }
    }

    private function seedScholarships(): void
    {
        foreach (DemoData::SCHOLARSHIPS as $key => $row) {
            $this->scholarships[$key] = Scholarship::query()->where('name', $row['name'])->first()
                ?? app(SaveScholarship::class)->handle([...$row, 'sponsor' => DemoData::SPONSOR, 'is_active' => true]);
        }
    }

    private function seedStudentOverrides(): void
    {
        $spp = $this->feeTypes['SPP'];
        $approver = $this->context->user('bendahara');

        foreach (DemoData::students() as $row) {
            $flag = collect(['yatim', 'tahfidz', 'wawancara'])->first(fn (string $flag): bool => in_array($flag, $row['flags'], true));

            if ($flag === null) {
                continue;
            }

            $student = $this->context->student($row['name']);

            if (FeeOverride::query()->where('student_id', $student->id)->where('fee_type_id', $spp->id)->exists()) {
                continue;
            }

            $scholarship = $this->scholarships[$flag] ?? null;

            app(SaveFeeOverride::class)->handle([
                'student_id' => $student->id,
                'fee_type_id' => $spp->id,
                'scholarship_id' => $scholarship?->id,
                'mode' => $scholarship?->mode ?? DemoData::INTERVIEW_DISCOUNT['mode'],
                'value' => $scholarship?->default_value ?? DemoData::INTERVIEW_DISCOUNT['value'],
                'reason' => $scholarship?->name ?? DemoData::INTERVIEW_DISCOUNT['reason'],
                'valid_from' => $this->context->previousYear->starts_on->toDateString(),
                'valid_until' => null,
            ], $approver);
        }
    }

    /**
     * Jalur SUBSIDI berbeasiswa uang masuk; jalur bawaan selain REGULER dinonaktifkan
     * bila masih aktif dan belum punya pendaftar (semua field dikirim ulang).
     */
    private function seedTracks(): void
    {
        $row = DemoData::SUBSIDY_TRACK;

        if (! AdmissionTrack::query()->where('code', $row['code'])->exists()) {
            app(SaveAdmissionTrack::class)->handle([
                'code' => $row['code'],
                'name' => $row['name'],
                'scholarship_id' => $this->scholarships[$row['scholarship']]->id,
                'terms' => $row['terms'],
                'is_active' => true,
                'fee_type_ids' => array_map(fn (string $fee): int => $this->feeTypes[$fee]->id, $row['fees']),
            ]);
        }

        $others = AdmissionTrack::query()
            ->whereNotIn('code', DemoData::ACTIVE_TRACKS)
            ->where('is_active', true)
            ->whereDoesntHave('applicants', fn ($query) => $query->withoutGlobalScopes())
            ->get();

        foreach ($others as $track) {
            app(SaveAdmissionTrack::class)->handle([
                'code' => $track->code,
                'name' => $track->name,
                'scholarship_id' => $track->scholarship_id,
                'terms' => $track->terms,
                'is_active' => false,
            ], $track);
        }
    }

    private function generateInvoices(): void
    {
        foreach ($this->context->calendar->billingPeriods() as $period) {
            app(GenerateMonthlyInvoices::class)->handle($period, $this->context->user('bendahara'));
        }
    }

    /**
     * Pola per santri aktif berombel tahun aktif (indeks `i` urut `DemoData`):
     * `i % 10` 0-5 lunas, 6-7 cicilan (bulan lalu separuh, bulan ini belum),
     * 8-9 menunggak (hanya periode pertama dibayar).
     */
    private function seedPayments(): void
    {
        $periods = $this->context->calendar->billingPeriods();
        $index = 0;
        $corrected = false;

        foreach (DemoData::students() as $row) {
            if ($row['a'] === null) {
                continue;
            }

            $i = $index++;
            $student = $this->context->student($row['name']);
            $pattern = match (true) {
                $i % 10 <= 5 => 'lunas',
                $i % 10 <= 7 => 'cicilan',
                default => 'tunggakan',
            };

            foreach ($periods as $position => $period) {
                $invoice = Invoice::withoutUnitScope()
                    ->where('student_id', $student->id)
                    ->where('billing_group', 'BULANAN')
                    ->whereDate('period', $period->toDateString())
                    ->where('status', '<>', Invoice::STATUS_CANCELLED)
                    ->first();

                if ($invoice === null || $invoice->total === 0 || $invoice->status === Invoice::STATUS_PAID) {
                    continue;
                }

                $amount = match ($pattern) {
                    'lunas' => $invoice->total,
                    'cicilan' => match ($position) {
                        0, 1 => $invoice->total,
                        2 => intdiv(intdiv($invoice->total, 2), 1000) * 1000,
                        default => 0,
                    },
                    default => $position === 0 ? $invoice->total : 0,
                };

                $paidOn = $period->setDay(5 + ($i % 5));

                if ($amount === 0 || $paidOn->gt($this->context->calendar->today)) {
                    continue;
                }

                if (! $corrected && $pattern === 'lunas' && $position === 0 && $invoice->total >= 2 * self::CORRECTION_SHORTFALL) {
                    $corrected = true;
                    $this->recordCorrection($invoice, $paidOn, $i);
                }

                if (Payment::withoutUnitScope()->where('invoice_id', $invoice->id)->exists()) {
                    continue;
                }

                $this->pay($invoice, $amount, $paidOn, $i);
            }
        }
    }

    private function pay(Invoice $invoice, int $amount, CarbonImmutable $paidOn, int $i): Payment
    {
        return app(RecordPayment::class)->handle($invoice, $this->context->user('bendahara'), [
            'amount' => $amount,
            'paid_on' => $paidOn->toDateString(),
            'method' => $i % 2 === 0 ? Payment::METHOD_CASH : Payment::METHOD_TRANSFER,
        ]);
    }

    /**
     * Pembayaran salah nominal yang dibatalkan, lalu pembayaran yang benar.
     */
    private function recordCorrection(Invoice $invoice, CarbonImmutable $paidOn, int $i): void
    {
        if (Payment::withoutUnitScope()->where('invoice_id', $invoice->id)->exists()) {
            return;
        }

        $wrong = $this->pay($invoice, $invoice->total - self::CORRECTION_SHORTFALL, $paidOn, $i);
        app(CancelPayment::class)->handle($wrong, $this->context->user('bendahara'), self::CORRECTION_REASON);
        $this->pay($invoice->refresh(), $invoice->total, $paidOn, $i);
    }
}

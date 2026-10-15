<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\ApplyFeeOverride;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membuat tagihan biaya `saat_daftar` untuk satu pendaftar (snapshot).
 *
 * Tarif per gelombang lewat `ResolveFeeRate`, keringanan pendaftar lewat
 * `ResolveFeeOverride::forApplicant`. Jenis biaya dengan `billing_group` sama
 * masuk satu tagihan. Idempotent: kelompok yang sudah punya tagihan aktif
 * tidak diubah. `applies_to` diabaikan (pendaftar belum mukim/nonmukim).
 *
 * Setiap kelompok dicoba maksimal `MAX_ATTEMPTS` kali; setiap percobaan
 * transaksi baru yang diawali kunci baris pendaftar. Bentrok nomor tagihan
 * ditangkap di luar transaksi.
 */
class GenerateRegistrationInvoices
{
    public const MAX_ATTEMPTS = 3;

    public const REASON_MISSING_RATE = 'missing_rate';

    public function __construct(
        private ResolveFeeRate $resolveFeeRate,
        private ResolveFeeOverride $resolveFeeOverride,
        private ApplyFeeOverride $applyFeeOverride,
        private GenerateInvoiceNumber $generateInvoiceNumber,
    ) {}

    /**
     * @return array{created_invoices: int, existing_invoices: int, skipped: list<array{fee_type_id: int, reason: string}>}
     *
     * @throws ValidationException pendaftar tidak lagi berstatus diajukan/diverifikasi
     * @throws RuntimeException nomor tagihan terus bentrok
     */
    public function handle(Applicant $applicant, ?User $by = null): array
    {
        if (! $applicant->isOpen()) {
            throw ValidationException::withMessages([
                'status' => __('pondok.psb.errors.applicant_closed'),
            ]);
        }

        $result = ['created_invoices' => 0, 'existing_invoices' => 0, 'skipped' => []];
        $wave = AdmissionWave::query()->with('academicYear')->findOrFail($applicant->admission_wave_id);
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $period = $today->startOfMonth();
        $waveLabel = "{$wave->name} {$wave->academicYear->name}";

        $feeTypes = FeeType::query()
            ->active()
            ->where('frequency', FeeType::FREQUENCY_ON_REGISTRATION)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];

        foreach ($feeTypes as $feeType) {
            $rate = $this->resolveFeeRate->handle($feeType, $wave->academicYear, $applicant->unit_id, null, null, $wave->id);
            $override = $this->resolveFeeOverride->forApplicant($applicant->id, $feeType, $period);
            $amounts = $this->applyFeeOverride->handle($rate?->amount, $override);

            if ($amounts === null) {
                $result['skipped'][] = ['fee_type_id' => $feeType->id, 'reason' => self::REASON_MISSING_RATE];

                Log::warning('Tagihan pendaftaran dilewati: tarif belum diatur', [
                    'registration_no' => $applicant->registration_no,
                    'fee_type_code' => $feeType->code,
                    'reason' => self::REASON_MISSING_RATE,
                ]);

                continue;
            }

            $groups[$feeType->billing_group][] = [
                'fee_type_id' => $feeType->id,
                'description' => __('pondok.applicant.invoice_item_description', ['fee_type' => $feeType->name, 'wave' => $waveLabel]),
                ...$amounts,
                'rate_source' => $rate === null ? InvoiceItem::RATE_SOURCE_MANUAL : InvoiceItem::RATE_SOURCE_UNIT,
            ];
        }

        $dueOn = $wave->closes_on->format('Y-m-d') >= $today->toDateString() ? $wave->closes_on : $today;

        foreach ($groups as $group => $rows) {
            $created = $this->createInvoice($applicant, $group, $rows, [
                'academic_year_id' => $wave->academic_year_id,
                'period' => $period,
                'due_on' => $dueOn,
                'generated_by' => $by?->id,
            ]);

            $result[$created ? 'created_invoices' : 'existing_invoices']++;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{academic_year_id: int, period: CarbonImmutable, due_on: mixed, generated_by: int|null}  $attributes
     * @return bool true bila tagihan baru dibuat, false bila kelompok sudah punya tagihan aktif
     */
    private function createInvoice(Applicant $applicant, string $group, array $rows, array $attributes): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($applicant, $group, $rows, $attributes): bool {
                    Applicant::withoutGlobalScopes()->lockForUpdate()->find($applicant->id);

                    if ($this->activeInvoiceExists($applicant, $group)) {
                        return false;
                    }

                    $invoice = new Invoice([
                        ...$attributes,
                        'invoice_no' => $this->generateInvoiceNumber->handle($attributes['period']),
                        'student_id' => null,
                        'applicant_id' => $applicant->id,
                        'billing_group' => $group,
                        'total' => array_sum(array_column($rows, 'amount')),
                        'paid_total' => 0,
                        'status' => Invoice::STATUS_UNPAID,
                    ]);
                    $invoice->syncStatus();
                    $invoice->save();
                    $invoice->items()->createMany($rows);

                    return true;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->activeInvoiceExists($applicant, $group)) {
                    return false;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    Log::error('Tagihan pendaftaran gagal: nomor tagihan terus bentrok', [
                        'registration_no' => $applicant->registration_no,
                        'billing_group' => $group,
                        'attempts' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(__('pondok.invoice.errors.number_conflict'), previous: $exception);
                }
            }
        }
    }

    private function activeInvoiceExists(Applicant $applicant, string $group): bool
    {
        return Invoice::withoutUnitScope()
            ->where('applicant_id', $applicant->id)
            ->where('billing_group', $group)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->exists();
    }
}

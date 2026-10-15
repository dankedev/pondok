<?php

namespace App\Actions\Psb\Concerns;

use App\Actions\Keuangan\ApplyFeeOverride;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Logika bersama tagihan pendaftar PSB (formulir F010, penerimaan F011).
 *
 * Kelas pemakai menyuntikkan `ResolveFeeRate`, `ResolveFeeOverride`,
 * `ApplyFeeOverride`, dan `GenerateInvoiceNumber` sebagai properti privat
 * bernama sama. Tarif per gelombang, keringanan pendaftar, satu tagihan per
 * `billing_group`, snapshot nominal. Setiap kelompok dicoba maksimal
 * `MAX_ATTEMPTS` kali; setiap percobaan transaksi baru yang diawali kunci baris
 * pendaftar. Bentrok nomor tagihan ditangkap di luar transaksi.
 *
 * @property ResolveFeeRate $resolveFeeRate
 * @property ResolveFeeOverride $resolveFeeOverride
 * @property ApplyFeeOverride $applyFeeOverride
 * @property GenerateInvoiceNumber $generateInvoiceNumber
 */
trait CreatesApplicantInvoices
{
    /**
     * Item tagihan per kelompok untuk jenis biaya aktif berfrekuensi `$frequency`.
     * Jenis biaya tanpa tarif (dan tanpa keringanan nominal) dilewati dan dicatat.
     *
     * @param  list<array{fee_type_id: int, reason: string}>  $skipped
     * @return array<string, list<array<string, mixed>>>
     */
    protected function invoiceGroups(Applicant $applicant, AdmissionWave $wave, string $frequency, CarbonImmutable $period, array &$skipped, string $logMessage): array
    {
        $waveLabel = "{$wave->name} {$wave->academicYear->name}";
        $groups = [];

        $feeTypes = FeeType::query()
            ->active()
            ->where('frequency', $frequency)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($feeTypes as $feeType) {
            $rate = $this->resolveFeeRate->handle($feeType, $wave->academicYear, $applicant->unit_id, null, null, $wave->id);
            $override = $this->resolveFeeOverride->forApplicant($applicant->id, $feeType, $period);
            $amounts = $this->applyFeeOverride->handle($rate?->amount, $override);

            if ($amounts === null) {
                $skipped[] = ['fee_type_id' => $feeType->id, 'reason' => self::REASON_MISSING_RATE];

                Log::warning($logMessage, [
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

        return $groups;
    }

    /**
     * `$ensureBillable` dijalankan di dalam transaksi tepat setelah baris
     * pendaftar dikunci (menerima baris terkunci, termasuk yang terhapus) dan
     * boleh melempar untuk menghentikan penagihan.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{academic_year_id: int, period: CarbonImmutable, due_on: mixed, generated_by: int|null}  $attributes
     * @param  Closure(?Applicant): void  $ensureBillable
     * @return bool true bila tagihan baru dibuat, false bila kelompok sudah punya tagihan aktif
     */
    protected function createApplicantInvoice(Applicant $applicant, string $group, array $rows, array $attributes, Closure $ensureBillable, string $errorMessage): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($applicant, $group, $rows, $attributes, $ensureBillable): bool {
                    $ensureBillable(Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicant->id));

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
                    Log::error($errorMessage, [
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

    protected function activeInvoiceExists(Applicant $applicant, string $group): bool
    {
        return Invoice::withoutUnitScope()
            ->where('applicant_id', $applicant->id)
            ->where('billing_group', $group)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->exists();
    }
}

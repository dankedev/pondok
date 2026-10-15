<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\ApplyFeeOverride;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Actions\Psb\Concerns\CreatesApplicantInvoices;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    use CreatesApplicantInvoices;

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

        $groups = $this->invoiceGroups(
            $applicant,
            $wave,
            FeeType::FREQUENCY_ON_REGISTRATION,
            $period,
            $result['skipped'],
            'Tagihan pendaftaran dilewati: tarif belum diatur',
        );

        $dueOn = $wave->closes_on->format('Y-m-d') >= $today->toDateString() ? $wave->closes_on : $today;

        foreach ($groups as $group => $rows) {
            $created = $this->createApplicantInvoice($applicant, $group, $rows, [
                'academic_year_id' => $wave->academic_year_id,
                'period' => $period,
                'due_on' => $dueOn,
                'generated_by' => $by?->id,
            ], fn (): null => null, 'Tagihan pendaftaran gagal: nomor tagihan terus bentrok');

            $result[$created ? 'created_invoices' : 'existing_invoices']++;
        }

        return $result;
    }
}

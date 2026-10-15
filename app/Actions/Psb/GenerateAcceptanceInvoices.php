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
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membuat tagihan biaya `saat_diterima` (uang pangkal, seragam, kitab) untuk
 * pendaftar diterima (snapshot), setelah beasiswa jalur diterapkan lewat
 * `ApplyTrackScholarship` agar potongannya ikut dihitung.
 *
 * Idempotent: kelompok yang sudah punya tagihan aktif tidak diubah. Setiap
 * percobaan kelompok membaca ulang status pada baris terkunci; pendaftar yang
 * tidak lagi diterima (atau terhapus) menghentikan penagihan kelompok itu dan
 * sisanya. `due_on` = awal tahun ajaran gelombang bila masih di depan, selain
 * itu hari ini. `applies_to` diabaikan.
 */
class GenerateAcceptanceInvoices
{
    use CreatesApplicantInvoices;

    public const MAX_ATTEMPTS = 3;

    public const REASON_MISSING_RATE = 'missing_rate';

    public function __construct(
        private ApplyTrackScholarship $applyTrackScholarship,
        private ResolveFeeRate $resolveFeeRate,
        private ResolveFeeOverride $resolveFeeOverride,
        private ApplyFeeOverride $applyFeeOverride,
        private GenerateInvoiceNumber $generateInvoiceNumber,
    ) {}

    /**
     * @return array{scholarship: array{created: list<int>, skipped: list<array{fee_type_id: int|null, reason: string}>}, created_invoices: int, existing_invoices: int, skipped: list<array{fee_type_id: int, reason: string}>}
     *
     * @throws ValidationException pendaftar tidak (lagi) diterima, atau keringanan gagal disimpan
     * @throws RuntimeException nomor tagihan terus bentrok
     */
    public function handle(Applicant $applicant, User $by): array
    {
        DB::transaction(fn () => ApplyTrackScholarship::ensureAccepted(
            Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicant->id),
        ));

        $result = [
            'scholarship' => $this->applyTrackScholarship->handle($applicant, $by),
            'created_invoices' => 0,
            'existing_invoices' => 0,
            'skipped' => [],
        ];

        $wave = AdmissionWave::query()->with('academicYear')->findOrFail($applicant->admission_wave_id);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $period = $today->startOfMonth();
        $startsOn = CarbonImmutable::parse($wave->academicYear->starts_on)->startOfDay();

        $groups = $this->invoiceGroups(
            $applicant,
            $wave,
            FeeType::FREQUENCY_ON_ACCEPTANCE,
            $period,
            $result['skipped'],
            'Tagihan penerimaan dilewati: tarif belum diatur',
        );

        foreach ($groups as $group => $rows) {
            $created = $this->createApplicantInvoice($applicant, $group, $rows, [
                'academic_year_id' => $wave->academic_year_id,
                'period' => $period,
                'due_on' => $startsOn->gt($today) ? $startsOn : $today,
                'generated_by' => $by->id,
            ], ApplyTrackScholarship::ensureAccepted(...), 'Tagihan penerimaan gagal: nomor tagihan terus bentrok');

            $result[$created ? 'created_invoices' : 'existing_invoices']++;
        }

        return $result;
    }

    /**
     * Ada jenis biaya `saat_diterima` aktif yang kelompoknya belum punya tagihan aktif.
     */
    public static function hasUnbilledGroups(Applicant $applicant): bool
    {
        $billed = $applicant->invoices()->withoutGlobalScopes()
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->pluck('billing_group');

        return FeeType::query()
            ->active()
            ->where('frequency', FeeType::FREQUENCY_ON_ACCEPTANCE)
            ->whereNotIn('billing_group', $billed)
            ->exists();
    }
}

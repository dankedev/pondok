<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Setting;
use App\Models\Unit;

/**
 * Syarat pembayaran daftar ulang (F040): jumlah terbayar tagihan penerimaan aktif
 * (bukan `batal`, memuat biaya `saat_diterima`) minimal nominal jenjang pendaftar
 * (`units.min_reregistration_amount`). Nominal `null` = wajib lunas, `0` = tanpa
 * syarat; bila total tagihan lebih kecil dari nominal, cukup lunas. Tagihan formulir
 * tidak dihitung. Murni baca, tanpa cakupan jenjang. Modul keuangan mati = tidak ada
 * syarat pembayaran.
 */
class CheckReRegistrationPayment
{
    public const REASON_MIN_PAYMENT = 'min_payment_not_met';

    public const REASON_INVOICES_MISSING = 'acceptance_invoices_missing';

    public function __construct(private ResolveFeeRate $resolveFeeRate) {}

    /**
     * @return array{applies: bool, minimum: int|null, required: int, total: int, paid: int, paid_percent: int, meets: bool, reason: string|null}
     */
    public function handle(Applicant $applicant): array
    {
        $minimum = Unit::query()->whereKey($applicant->unit_id)->value('min_reregistration_amount');
        $minimum = $minimum === null ? null : (int) $minimum;
        $result = ['applies' => false, 'minimum' => $minimum, 'required' => 0, 'total' => 0, 'paid' => 0, 'paid_percent' => 100, 'meets' => true, 'reason' => null];

        if (! Setting::moduleEnabled('keuangan')) {
            return $result;
        }

        $result['applies'] = true;
        $invoices = $applicant->acceptanceInvoices()->get(['invoices.id', 'invoices.total', 'invoices.paid_total']);

        if ($invoices->isEmpty()) {
            if ($this->hasAcceptanceRate($applicant)) {
                return [...$result, 'meets' => false, 'reason' => self::REASON_INVOICES_MISSING];
            }

            return $result;
        }

        $total = (int) $invoices->sum('total');
        $paid = (int) $invoices->sum('paid_total');
        $required = min($minimum ?? $total, $total);
        $meets = $paid >= $required;

        return [
            ...$result,
            'required' => $required,
            'total' => $total,
            'paid' => $paid,
            'paid_percent' => $total === 0 ? 100 : intdiv($paid * 100, $total),
            'meets' => $meets,
            'reason' => $meets ? null : self::REASON_MIN_PAYMENT,
        ];
    }

    /**
     * Ada jenis biaya `saat_diterima` aktif yang bertarif untuk gelombang dan jenjang pendaftar.
     */
    private function hasAcceptanceRate(Applicant $applicant): bool
    {
        $wave = AdmissionWave::query()->with('academicYear')->find($applicant->admission_wave_id);

        if ($wave === null) {
            return false;
        }

        return FeeType::query()
            ->active()
            ->where('frequency', FeeType::FREQUENCY_ON_ACCEPTANCE)
            ->get()
            ->contains(fn (FeeType $type): bool => $this->resolveFeeRate->handle($type, $wave->academicYear, $applicant->unit_id, null, null, $wave->id) !== null);
    }
}

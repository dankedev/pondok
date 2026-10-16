<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Syarat pembayaran daftar ulang: persen terbayar tagihan penerimaan aktif
 * (bukan `batal`, memuat biaya `saat_diterima`) minimal `psb.min_paid_percent`.
 * Tagihan formulir tidak dihitung; total 0 dianggap 100%. Murni baca, tanpa
 * cakupan jenjang. Modul keuangan mati = tidak ada syarat pembayaran.
 */
class CheckReRegistrationPayment
{
    public const SETTING_KEY = 'psb.min_paid_percent';

    public const DEFAULT_MIN_PERCENT = 50;

    public const REASON_MIN_PAYMENT = 'min_payment_not_met';

    public const REASON_INVOICES_MISSING = 'acceptance_invoices_missing';

    public function __construct(private ResolveFeeRate $resolveFeeRate) {}

    /**
     * @return array{applies: bool, required_percent: int, total: int, paid: int, paid_percent: int, meets: bool, reason: string|null}
     */
    public function handle(Applicant $applicant): array
    {
        $required = $this->requiredPercent();
        $result = ['applies' => false, 'required_percent' => $required, 'total' => 0, 'paid' => 0, 'paid_percent' => 100, 'meets' => true, 'reason' => null];

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
        $meets = $paid * 100 >= $total * $required;

        return [
            ...$result,
            'total' => $total,
            'paid' => $paid,
            'paid_percent' => $total === 0 ? 100 : intdiv($paid * 100, $total),
            'meets' => $meets,
            'reason' => $meets ? null : self::REASON_MIN_PAYMENT,
        ];
    }

    /**
     * Bilangan bulat 0–100 (int atau string digit); selain itu bawaan 50 + peringatan log.
     */
    public function requiredPercent(): int
    {
        $value = Setting::get(self::SETTING_KEY, self::DEFAULT_MIN_PERCENT);

        if (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (is_int($value) && $value >= 0 && $value <= 100) {
            return $value;
        }

        Log::warning('Setting psb.min_paid_percent tidak valid, memakai bawaan', ['value' => $value, 'default' => self::DEFAULT_MIN_PERCENT]);

        return self::DEFAULT_MIN_PERCENT;
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

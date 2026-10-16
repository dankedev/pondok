<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\EndFeeOverride;
use App\Models\AdmissionTrack;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menolak pendaftar (`diajukan`/`diverifikasi`/`cadangan`) atau membatalkan
 * penerimaan (`diterima`), catatan wajib.
 *
 * Dalam satu transaksi: keputusan dicatat, setiap tagihan aktif pendaftar
 * tanpa pembayaran dibatalkan lewat `CancelInvoice` (langkah sistem tanpa
 * pemeriksaan `cancel:invoice`; `$by` menjadi causer), dan keringanan
 * otomatis jalur yang masih berlaku setelah hari ini diakhiri hari ini.
 * Pembatalan penerimaan ditolak bila pendaftar sudah menjadi santri atau
 * tagihan penerimaannya sudah dibayar. Gagal di langkah mana pun = rollback.
 */
class RejectApplicant
{
    public function __construct(
        private RecordApplicantDecision $decision,
        private CancelInvoice $cancelInvoice,
        private EndFeeOverride $endFeeOverride,
    ) {}

    /**
     * @return array{applicant: Applicant, cancelled_invoices: int, ended_overrides: int}
     *
     * @throws ValidationException
     */
    public function handle(Applicant $applicant, User $by, string $note): array
    {
        return DB::transaction(function () use ($applicant, $by, $note): array {
            $locked = $this->decision->lock($applicant);
            $this->decision->ensureCanTransition($locked, Applicant::STATUS_REJECTED);

            if ($locked->status === Applicant::STATUS_ACCEPTED) {
                $this->ensureAcceptanceCancellable($locked);
            }

            $this->decision->handle($locked, $by, Applicant::STATUS_REJECTED, $note, noteRequired: true);

            $cancelled = 0;
            $invoices = Invoice::withoutUnitScope()
                ->where('applicant_id', $locked->id)
                ->where('status', '<>', Invoice::STATUS_CANCELLED)
                ->where('paid_total', 0)
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                $this->cancelInvoice->handle($invoice, $by, __('pondok.applicant.cancel_reason_rejected'));
                $cancelled++;
            }

            return [
                'applicant' => $this->decision->sync($applicant, $locked),
                'cancelled_invoices' => $cancelled,
                'ended_overrides' => $this->endTrackScholarship($locked),
            ];
        });
    }

    private function ensureAcceptanceCancellable(Applicant $locked): void
    {
        if ($locked->isConverted()) {
            $this->fail('applicant_converted');
        }

        if ($locked->acceptanceInvoices()->where('paid_total', '>', 0)->exists()) {
            $this->fail('acceptance_has_payments');
        }
    }

    /**
     * Keringanan program jalur yang sudah mulai dan masih berlaku setelah hari
     * ini. Yang berakhir hari ini sudah selesai dan dilewati.
     */
    private function endTrackScholarship(Applicant $locked): int
    {
        $scholarshipId = AdmissionTrack::query()->whereKey($locked->admission_track_id)->value('scholarship_id');

        if ($scholarshipId === null) {
            return 0;
        }

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $overrides = FeeOverride::query()
            ->where('applicant_id', $locked->id)
            ->where('scholarship_id', $scholarshipId)
            ->whereDate('valid_from', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>', $today->toDateString()))
            ->lockForUpdate()
            ->get();

        foreach ($overrides as $override) {
            $this->endFeeOverride->handle($override, $today);
        }

        return $overrides->count();
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'applicant' => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}

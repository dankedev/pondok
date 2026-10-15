<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\Setting;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * `diverifikasi`/`cadangan` → `diterima`, catatan opsional.
 *
 * Penerimaan di-commit lebih dulu. Setelah itu, bila modul keuangan aktif,
 * beasiswa jalur dan tagihan penerimaan dibuat lewat
 * `GenerateAcceptanceInvoices`; kegagalan langkah itu dicatat dan dilaporkan
 * (`billing_failed`) tanpa membatalkan penerimaan. Panitia mengulang lewat
 * aksi "Buat tagihan penerimaan" (idempotent).
 */
class AcceptApplicant
{
    public function __construct(
        private RecordApplicantDecision $decision,
        private GenerateAcceptanceInvoices $generateAcceptanceInvoices,
    ) {}

    /**
     * @return array{applicant: Applicant, billing: array<string, mixed>|null, billing_failed: bool}
     *
     * @throws ValidationException keputusan tidak sah
     */
    public function handle(Applicant $applicant, User $by, ?string $note = null): array
    {
        DB::transaction(function () use ($applicant, $by, $note): void {
            $locked = $this->decision->lock($applicant);
            $this->decision->handle($locked, $by, Applicant::STATUS_ACCEPTED, $note, noteRequired: false);
            $this->decision->sync($applicant, $locked);
        });

        $result = ['applicant' => $applicant, 'billing' => null, 'billing_failed' => false];

        if (! Setting::moduleEnabled('keuangan')) {
            return $result;
        }

        try {
            $result['billing'] = $this->generateAcceptanceInvoices->handle($applicant, $by);
        } catch (Exception $exception) {
            Log::error('Tagihan penerimaan gagal dibuat setelah pendaftar diterima', [
                'registration_no' => $applicant->registration_no,
                'error' => $exception->getMessage(),
            ]);

            $result['billing_failed'] = true;
        }

        return $result;
    }
}

<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `diajukan` → `diverifikasi`: biaya formulir lunas (`registrationFeeCleared()`)
 * dan semua syarat berkas wajib sudah `valid`. Catatan opsional.
 */
class MarkApplicantVerified
{
    public function __construct(private RecordApplicantDecision $decision) {}

    /**
     * @throws ValidationException
     */
    public function handle(Applicant $applicant, User $by, ?string $note = null): Applicant
    {
        return DB::transaction(function () use ($applicant, $by, $note): Applicant {
            $locked = $this->decision->lock($applicant);
            $this->decision->ensureCanTransition($locked, Applicant::STATUS_VERIFIED);

            if (! $locked->registrationFeeCleared()) {
                throw ValidationException::withMessages([
                    'applicant' => __('pondok.psb.errors.registration_fee_unpaid'),
                ]);
            }

            $missing = $locked->missingRequiredDocuments()->count();

            if ($missing > 0) {
                throw ValidationException::withMessages([
                    'applicant' => __('pondok.psb.errors.documents_incomplete', ['count' => $missing]),
                ]);
            }

            $this->decision->handle($locked, $by, Applicant::STATUS_VERIFIED, $note, noteRequired: false);

            return $this->decision->sync($applicant, $locked);
        });
    }
}

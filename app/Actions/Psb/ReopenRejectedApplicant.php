<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemulihan `ditolak` → `diajukan` (permission `reopen:applicant` dicek di panel), catatan wajib. Tanpa efek keuangan: tagihan `batal` dan keringanan yang diakhiri tidak dipulihkan; tagihan formulir baru dibuat lewat "Buat tagihan pendaftaran".
 */
class ReopenRejectedApplicant
{
    public function __construct(private RecordApplicantDecision $decision) {}

    /**
     * @throws ValidationException
     */
    public function handle(Applicant $applicant, User $by, string $note): Applicant
    {
        return DB::transaction(function () use ($applicant, $by, $note): Applicant {
            $locked = $this->decision->lock($applicant);
            $this->decision->handle($locked, $by, Applicant::STATUS_SUBMITTED, $note, noteRequired: true, from: [Applicant::STATUS_REJECTED]);

            return $this->decision->sync($applicant, $locked);
        });
    }
}

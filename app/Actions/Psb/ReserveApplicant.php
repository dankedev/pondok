<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `diverifikasi` → `cadangan`, catatan wajib. Tanpa efek keuangan.
 */
class ReserveApplicant
{
    public function __construct(private RecordApplicantDecision $decision) {}

    /**
     * @throws ValidationException
     */
    public function handle(Applicant $applicant, User $by, string $note): Applicant
    {
        return DB::transaction(function () use ($applicant, $by, $note): Applicant {
            $locked = $this->decision->lock($applicant);
            $this->decision->handle($locked, $by, Applicant::STATUS_RESERVE, $note, noteRequired: true);

            return $this->decision->sync($applicant, $locked);
        });
    }
}

<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `diverifikasi` → `diajukan`, catatan wajib. Status berkas tidak diubah; pendaftar bisa mengunggah ulang berkas yang belum `valid`.
 */
class ReturnApplicantToSubmitted
{
    public function __construct(private RecordApplicantDecision $decision) {}

    /**
     * @throws ValidationException
     */
    public function handle(Applicant $applicant, User $by, string $note): Applicant
    {
        return DB::transaction(function () use ($applicant, $by, $note): Applicant {
            $locked = $this->decision->lock($applicant);
            $this->decision->handle($locked, $by, Applicant::STATUS_SUBMITTED, $note, noteRequired: true, from: [Applicant::STATUS_VERIFIED]);

            return $this->decision->sync($applicant, $locked);
        });
    }
}

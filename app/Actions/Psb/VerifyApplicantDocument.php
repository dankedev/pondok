<?php

namespace App\Actions\Psb;

use App\Models\ApplicantDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Keputusan panitia atas satu berkas pendaftar: `valid` atau `ditolak`
 * (wajib catatan 1-500 karakter). Keputusan boleh diubah selama pendaftar
 * masih `diajukan`/`diverifikasi`. Activity `psb` dicatat model.
 */
class VerifyApplicantDocument
{
    public const MAX_NOTE = 500;

    public function handle(ApplicantDocument $document, User $by, string $status, ?string $note = null): ApplicantDocument
    {
        if (! in_array($status, [ApplicantDocument::STATUS_VALID, ApplicantDocument::STATUS_REJECTED], true)) {
            $this->fail('status', 'document_status_invalid');
        }

        $note = trim((string) $note);

        if ($status === ApplicantDocument::STATUS_REJECTED && ($note === '' || mb_strlen($note) > self::MAX_NOTE)) {
            $this->fail('note', 'note_required');
        }

        if (! $document->applicant->isOpen()) {
            $this->fail('status', 'applicant_closed');
        }

        $document->forceFill([
            'status' => $status,
            'note' => $status === ApplicantDocument::STATUS_REJECTED ? $note : null,
            'verified_by' => $by->id,
            'verified_at' => now(),
        ])->save();

        return $document;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}

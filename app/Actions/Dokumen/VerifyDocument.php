<?php

namespace App\Actions\Dokumen;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menandai dokumen current `valid` atau `ditolak`. Koreksi `valid ↔ ditolak`
 * diizinkan; dokumen berjenis masa berlaku yang sudah kedaluwarsa tidak bisa
 * divalidasi. Permission dicek policy/UI.
 */
class VerifyDocument
{
    public function handle(Document $document, string $status, User $verifier): Document
    {
        if (! in_array($status, [Document::STATUS_VALID, Document::STATUS_REJECTED], true)) {
            $this->fail('status_invalid');
        }

        if (! $document->isCurrent()) {
            $this->fail('document_replaced');
        }

        if ($document->status === $status) {
            $this->fail('status_unchanged');
        }

        if ($status === Document::STATUS_VALID && $document->documentType?->has_expiry && $document->isExpired()) {
            $this->fail('document_expired');
        }

        $from = $document->status;

        DB::transaction(function () use ($document, $status, $verifier, $from): void {
            $document->forceFill([
                'status' => $status,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ])->save();

            activity('dokumen')
                ->performedOn($document)
                ->causedBy($verifier)
                ->event('verified')
                ->withProperties(['from' => $from, 'to' => $status])
                ->log('verified');
        });

        return $document;
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'status' => __("pondok.dokumen.errors.{$reason}"),
        ]);
    }
}

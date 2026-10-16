<?php

namespace App\Actions\Dokumen;

use App\Models\IssuedLetter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membatalkan surat keluar (status `batal`) tanpa menghapusnya. Nomor, kode
 * verifikasi, dan snapshot tidak berubah; nomor tidak dipakai ulang. Status
 * dibaca dari baris yang dikunci, bukan dari instance yang mungkin basi.
 */
class CancelIssuedLetter
{
    public function handle(IssuedLetter $letter, User $user, string $reason): IssuedLetter
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > IssuedLetter::REASON_MAX_LENGTH) {
            $this->fail('reason', 'reason_invalid');
        }

        $cancelled = DB::transaction(function () use ($letter, $user, $reason): IssuedLetter {
            $current = IssuedLetter::withoutGlobalScopes()->lockForUpdate()->findOrFail($letter->getKey());

            if (! $current->isCancellable()) {
                $this->fail('letter', 'already_cancelled');
            }

            $current->forceFill([
                'status' => IssuedLetter::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            activity('dokumen')
                ->performedOn($current)
                ->causedBy($user)
                ->event('cancelled')
                ->withProperties(['letter_no' => $current->letter_no, 'reason' => $reason])
                ->log('cancelled');

            return $current;
        });

        $letter->setRawAttributes($cancelled->getAttributes(), sync: true);

        return $cancelled;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.dokumen.errors.{$reason}"),
        ]);
    }
}

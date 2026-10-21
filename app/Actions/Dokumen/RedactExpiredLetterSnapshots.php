<?php

namespace App\Actions\Dokumen;

use App\Models\IssuedLetter;
use App\Models\Setting;

/**
 * Redaksi data pribadi snapshot surat keluar setelah masa simpan (F042, UU PDP).
 * Masa simpan `retention.letter_snapshot_years` (tahun); kosong = tidak ada redaksi.
 * Surat semua status dan lintas jenjang yang `issued_on` ≤ hari ini − N tahun:
 * `snapshot.student` dipangkas menjadi nama termasker dan `snapshot_redacted_at`
 * diisi. Surat tidak dihapus; nomor, status, kode verifikasi, penandatangan, dan
 * profil lembaga tetap. Surat yang sudah diredaksi dilewati (idempoten).
 */
class RedactExpiredLetterSnapshots
{
    public function handle(): int
    {
        $years = Setting::get('retention.letter_snapshot_years');

        if (! is_numeric($years) || (int) $years < 1) {
            return 0;
        }

        $cutoff = now('Asia/Jakarta')->subYears((int) $years)->toDateString();
        $redacted = 0;

        IssuedLetter::query()
            ->withoutGlobalScopes()
            ->whereNull('snapshot_redacted_at')
            ->whereDate('issued_on', '<=', $cutoff)
            ->lazyById(200)
            ->each(function (IssuedLetter $letter) use (&$redacted): void {
                $snapshot = (array) $letter->snapshot;
                $snapshot['student'] = ['name' => $letter->maskedStudentName()];

                $letter->forceFill([
                    'snapshot' => $snapshot,
                    'snapshot_redacted_at' => now(),
                ])->save();

                $redacted++;
            });

        if ($redacted > 0) {
            activity('sistem')
                ->event('redact')
                ->withProperties(['redacted' => $redacted])
                ->log(__('pondok.data_retention.activity_redacted'));
        }

        return $redacted;
    }
}

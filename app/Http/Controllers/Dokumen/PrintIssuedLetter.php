<?php

namespace App\Http\Controllers\Dokumen;

use App\Http\Controllers\Controller;
use App\Models\IssuedLetter;
use App\Models\Setting;
use App\Services\QrCodeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Halaman cetak surat keluar (HTML mandiri tanpa aset eksternal): A4 untuk
 * surat, ukuran kartu CR80 untuk kartu santri. Isi diambil dari snapshot,
 * logo dari profil sekolah terkini. Snapshot yang sudah diredaksi (masa simpan,
 * F042) hanya berisi nama termasker dan diberi catatan. QR gagal dibuat → halaman tetap tampil
 * dengan URL dan kode verifikasi.
 */
class PrintIssuedLetter extends Controller
{
    public function __invoke(IssuedLetter $issuedLetter, QrCodeRenderer $qrCodeRenderer): View
    {
        Gate::authorize('view', $issuedLetter);

        $issuedLetter->load(['issuer', 'canceller']);
        $logoPath = Setting::get('school.logo_path');

        return view($issuedLetter->type === IssuedLetter::TYPE_STUDENT_CARD ? 'dokumen.student-card' : 'dokumen.letter', [
            'letter' => $issuedLetter,
            'snapshot' => $issuedLetter->snapshot ?? [],
            'logo' => is_string($logoPath) && $logoPath !== '' && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->url($logoPath) : null,
            'qr' => $this->qr($issuedLetter, $qrCodeRenderer),
            'printedAt' => now('Asia/Jakarta'),
            'redactedAt' => $issuedLetter->snapshot_redacted_at,
        ]);
    }

    private function qr(IssuedLetter $letter, QrCodeRenderer $qrCodeRenderer): ?string
    {
        try {
            return $qrCodeRenderer->svg($letter->verificationUrl(), __('pondok.letter.qr_label', ['number' => $letter->letter_no]));
        } catch (Throwable $exception) {
            Log::warning('QR verifikasi surat gagal dibuat', ['issued_letter_id' => $letter->getKey(), 'error' => $exception->getMessage()]);

            return null;
        }
    }
}

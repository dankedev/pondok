<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh bukti transfer dari disk `private` sebagai lampiran. Pembayaran di luar
 * cakupan jenjang tidak ditemukan lewat route model binding (404).
 */
class DownloadPaymentProof extends Controller
{
    public function __invoke(Payment $payment): StreamedResponse
    {
        Gate::authorize('view', $payment);

        $disk = Storage::disk(Payment::PROOF_DISK);
        $path = $payment->proof_path;

        abort_if(blank($path) || ! $disk->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = 'bukti-transfer-'.$payment->receipt_no.($extension !== '' ? ".{$extension}" : '');

        return $disk->download($path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }
}

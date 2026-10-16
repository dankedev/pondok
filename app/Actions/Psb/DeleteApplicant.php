<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\CancelInvoice;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menghapus (soft delete) pendaftaran spam atau ganda. Ditolak bila sudah ada
 * pembayaran atau pendaftar sudah menjadi santri. Tagihan aktif dibatalkan
 * lewat `CancelInvoice`; berkas dan nomor pendaftaran tetap (nomor tidak
 * dipakai ulang), kuota gelombang terbebas.
 */
class DeleteApplicant
{
    public function __construct(private CancelInvoice $cancelInvoice) {}

    public function handle(Applicant $applicant, User $by): void
    {
        $invoices = Invoice::withoutUnitScope()
            ->where('applicant_id', $applicant->id)
            ->where('status', '<>', Invoice::STATUS_CANCELLED);

        if ((clone $invoices)->where('paid_total', '>', 0)->exists()) {
            $this->fail('applicant_has_payments');
        }

        if ($applicant->isConverted()) {
            $this->fail('applicant_converted');
        }

        DB::transaction(function () use ($applicant, $by, $invoices): void {
            foreach ($invoices->get() as $invoice) {
                $this->cancelInvoice->handle($invoice, $by, __('pondok.applicant.cancel_reason_deleted'));
            }

            $applicant->delete();
        });
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'applicant' => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}

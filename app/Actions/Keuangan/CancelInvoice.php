<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membatalkan tagihan yang belum ada pembayarannya: status menjadi `batal`,
 * item, total, dan barisnya tetap ada. Generator berikutnya membuat tagihan
 * baru bernomor baru untuk kombinasi santri, periode, dan kelompok ini.
 *
 * Tepat satu baris activity per pembatalan: log otomatis model dimatikan,
 * diganti satu log manual yang membawa status lama, status baru, dan alasan.
 */
class CancelInvoice
{
    public function handle(Invoice $invoice, User $user, string $reason): Invoice
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => __('pondok.invoice.errors.reason_invalid'),
            ]);
        }

        // Status dan pembayaran divalidasi dari baris terbaru yang dikunci, bukan dari
        // instance yang mungkin basi (dibatalkan atau dibayar di tab/proses lain).
        DB::transaction(function () use ($invoice, $user, $reason): void {
            $current = Invoice::withoutUnitScope()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($current->status === Invoice::STATUS_CANCELLED) {
                $this->fail('already_cancelled');
            }

            if ($current->paid_total > 0) {
                $this->fail('has_payments');
            }

            $oldStatus = $current->status;

            activity()->withoutLogs(function () use ($current): void {
                $current->status = Invoice::STATUS_CANCELLED;
                $current->save();
            });

            activity('keuangan')
                ->performedOn($current)
                ->causedBy($user)
                ->event('updated')
                ->withProperties([
                    'old' => ['status' => $oldStatus],
                    'attributes' => ['status' => Invoice::STATUS_CANCELLED],
                    'reason' => $reason,
                ])
                ->log(__('pondok.invoice.activity_cancelled'));

            $invoice->setRawAttributes($current->getAttributes(), sync: true);
        });

        return $invoice;
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'invoice' => __("pondok.invoice.errors.{$reason}"),
        ]);
    }
}

<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Membatalkan pembayaran yang salah input: status menjadi `batal`, terbayar
 * item dan tagihan dikurangi sebesar alokasinya, lalu status tagihan dihitung
 * ulang. Baris pembayaran, nomor kuitansi, alokasi, dan bukti transfer tetap
 * tersimpan. Pembayaran lain pada tagihan yang sama tidak dialokasikan ulang.
 *
 * Kunci diambil berurutan tagihan lalu pembayaran (sama dengan `RecordPayment`).
 * Tepat satu baris activity per pembatalan.
 */
class CancelPayment
{
    public function handle(Payment $payment, User $user, string $reason): Payment
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => __('pondok.payment.errors.reason_invalid'),
            ]);
        }

        DB::transaction(function () use ($payment, $user, $reason): void {
            $invoice = Invoice::withoutUnitScope()->lockForUpdate()->findOrFail($payment->invoice_id);
            $current = Payment::withoutUnitScope()->lockForUpdate()->findOrFail($payment->getKey());

            if ($current->status === Payment::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'payment' => __('pondok.payment.errors.already_cancelled'),
                ]);
            }

            $oldInvoiceStatus = $invoice->status;

            activity()->withoutLogs(function () use ($current, $invoice, $user, $reason): void {
                $current->forceFill([
                    'status' => Payment::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->getKey(),
                    'cancel_reason' => $reason,
                ])->save();

                $allocations = $current->allocations()->get();
                $items = InvoiceItem::query()
                    ->whereIn('id', $allocations->pluck('invoice_item_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($allocations as $allocation) {
                    $item = $items[$allocation->invoice_item_id];

                    if ($item->paid_amount < $allocation->amount) {
                        throw new LogicException("Terbayar item {$item->id} akan menjadi negatif.");
                    }

                    $item->paid_amount -= $allocation->amount;
                    $item->save();
                }

                if ($invoice->paid_total < $current->amount) {
                    throw new LogicException("Terbayar tagihan {$invoice->id} akan menjadi negatif.");
                }

                $invoice->paid_total -= $current->amount;
                $invoice->syncStatus();
                $invoice->save();
            });

            activity('keuangan')
                ->performedOn($current)
                ->causedBy($user)
                ->event('updated')
                ->withProperties([
                    'old' => ['status' => Payment::STATUS_RECEIVED],
                    'attributes' => ['status' => Payment::STATUS_CANCELLED],
                    'reason' => $reason,
                    'invoice_id' => $invoice->getKey(),
                    'invoice_status' => ['old' => $oldInvoiceStatus, 'new' => $invoice->status],
                ])
                ->log(__('pondok.payment.activity_cancelled'));

            $payment->setRawAttributes($current->getAttributes(), sync: true);
        });

        return $payment;
    }
}

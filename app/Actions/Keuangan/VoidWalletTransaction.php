<?php

namespace App\Actions\Keuangan;

use App\Models\Student;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membatalkan transaksi uang saku yang salah catat: status menjadi `batal`,
 * baris tetap tersimpan. Membatalkan transaksi masuk yang membuat saldo
 * negatif ditolak. Kunci diambil berurutan santri lalu transaksi. Tepat satu
 * baris activity per pembatalan.
 */
class VoidWalletTransaction
{
    public function handle(WalletTransaction $transaction, User $user, string $reason): WalletTransaction
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => __('pondok.wallet.errors.reason_invalid')]);
        }

        DB::transaction(function () use ($transaction, $user, $reason): void {
            $student = Student::withoutUnitScope()->lockForUpdate()->find($transaction->student_id);

            if ($student === null) {
                throw ValidationException::withMessages(['transaction' => __('pondok.wallet.errors.student_unavailable')]);
            }

            $unitIds = $user->scopedUnitIds();

            if ($unitIds !== [] && ! in_array((int) $student->unit_id, $unitIds, true)) {
                throw ValidationException::withMessages(['transaction' => __('pondok.wallet.errors.out_of_scope')]);
            }

            $current = WalletTransaction::withoutUnitScope()->lockForUpdate()->findOrFail($transaction->getKey());

            if ($current->status === WalletTransaction::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['transaction' => __('pondok.wallet.errors.already_cancelled')]);
            }

            if ($current->type->isCredit() && WalletBalance::forStudent($student->getKey()) < $current->amount) {
                throw ValidationException::withMessages(['transaction' => __('pondok.wallet.errors.balance_would_be_negative')]);
            }

            activity()->withoutLogs(function () use ($current, $user, $reason): void {
                $current->forceFill([
                    'status' => WalletTransaction::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->getKey(),
                    'cancel_reason' => $reason,
                ])->save();
            });

            activity('keuangan')
                ->performedOn($current)
                ->causedBy($user)
                ->event('updated')
                ->withProperties([
                    'old' => ['status' => WalletTransaction::STATUS_SUCCESS],
                    'attributes' => ['status' => WalletTransaction::STATUS_CANCELLED],
                    'reason' => $reason,
                ])
                ->log(__('pondok.wallet.activity_cancelled'));

            $transaction->setRawAttributes($current->getAttributes(), sync: true);
        });

        return $transaction;
    }
}

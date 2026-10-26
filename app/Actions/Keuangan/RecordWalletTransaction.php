<?php

namespace App\Actions\Keuangan;

use App\Enums\WalletTransactionType;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mencatat setor, tarik, atau koreksi uang saku. Baris santri dikunci dalam
 * transaksi DB (sama pola `RecordPayment`), saldo dihitung dari ledger, dan
 * mutasi keluar yang membuat saldo negatif ditolak. Tepat satu baris activity.
 */
class RecordWalletTransaction
{
    public const MAX_AMOUNT = 999_999_999;

    /**
     * @param  array{type: string, amount: mixed, occurred_on: mixed, note?: ?string}  $data
     */
    public function handle(Student $student, User $user, array $data): WalletTransaction
    {
        $type = WalletTransactionType::tryFrom((string) ($data['type'] ?? ''));
        $amount = $this->parseAmount($data['amount'] ?? null);
        $occurredOn = $this->parseDate($data['occurred_on'] ?? null);
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        $note = $note === '' ? null : $note;

        if ($type === null || $amount === null) {
            throw ValidationException::withMessages(['amount' => __('pondok.wallet.errors.amount_invalid')]);
        }

        if ($occurredOn->isAfter(CarbonImmutable::now('Asia/Jakarta')->startOfDay())) {
            throw ValidationException::withMessages(['occurred_on' => __('pondok.wallet.errors.date_future')]);
        }

        if ($note !== null && mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => __('pondok.wallet.errors.note_invalid')]);
        }

        if ($type->isCorrection() && $note === null) {
            throw ValidationException::withMessages(['note' => __('pondok.wallet.errors.note_required')]);
        }

        return DB::transaction(function () use ($student, $user, $type, $amount, $occurredOn, $note): WalletTransaction {
            $locked = Student::withoutUnitScope()->lockForUpdate()->find($student->getKey());

            if ($locked === null) {
                throw ValidationException::withMessages(['student' => __('pondok.wallet.errors.student_unavailable')]);
            }

            $unitIds = $user->scopedUnitIds();

            if ($unitIds !== [] && ! in_array((int) $locked->unit_id, $unitIds, true)) {
                throw ValidationException::withMessages(['student' => __('pondok.wallet.errors.out_of_scope')]);
            }

            $balance = WalletBalance::forStudent($locked->getKey());

            if (! $type->isCredit() && $amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => __('pondok.wallet.errors.insufficient_balance', ['balance' => number_format($balance, 0, ',', '.')]),
                ]);
            }

            $transaction = activity()->withoutLogs(fn (): WalletTransaction => WalletTransaction::create([
                'student_id' => $locked->getKey(),
                'type' => $type,
                'amount' => $amount,
                'occurred_on' => $occurredOn->toDateString(),
                'note' => $note,
                'recorded_by' => $user->getKey(),
            ]));

            activity('keuangan')
                ->performedOn($transaction)
                ->causedBy($user)
                ->event('created')
                ->withProperties(['attributes' => [
                    'student_id' => $transaction->student_id,
                    'type' => $type->value,
                    'amount' => $amount,
                    'occurred_on' => $transaction->occurred_on->toDateString(),
                    'status' => $transaction->status,
                ]])
                ->log(__('pondok.wallet.activity_recorded'));

            return $transaction;
        });
    }

    private function parseAmount(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_AMOUNT) {
            return null;
        }

        return $value;
    }

    private function parseDate(mixed $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value, 'Asia/Jakarta')->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_on' => __('pondok.wallet.errors.date_future')]);
        }
    }
}

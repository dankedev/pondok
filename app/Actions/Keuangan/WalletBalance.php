<?php

namespace App\Actions\Keuangan;

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;

/**
 * Saldo uang saku = masuk dikurangi keluar pada transaksi `berhasil`, dihitung
 * dari ledger (tanpa kolom saldo). Query agregat satu tempat untuk Action,
 * portal, dan rekap. Tidak terkena cakupan jenjang: cakupan dijaga pemanggil.
 */
class WalletBalance
{
    public static function forStudent(int $studentId): int
    {
        $credits = collect(WalletTransactionType::cases())
            ->filter(fn (WalletTransactionType $type): bool => $type->isCredit())
            ->map(fn (WalletTransactionType $type): string => $type->value)
            ->all();

        $rows = WalletTransaction::withoutUnitScope()
            ->where('student_id', $studentId)
            ->where('status', WalletTransaction::STATUS_SUCCESS)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return $rows->sum(fn ($total, string $type): int => in_array($type, $credits, true) ? (int) $total : -(int) $total);
    }
}

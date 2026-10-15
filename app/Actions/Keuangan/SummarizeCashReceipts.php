<?php

namespace App\Actions\Keuangan;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan kas (F008): uang yang benar-benar diterima berdasarkan tanggal
 * bayar (`paid_on`, bukan `created_at`), hanya pembayaran berstatus `diterima`
 * saat laporan dibaca. Pembayaran yang dibatalkan kemudian hilang dari bulan
 * mana pun. Rincian per jenis biaya dibaca dari `payment_allocations`.
 */
class SummarizeCashReceipts
{
    /**
     * @return array{
     *     total: int,
     *     payment_count: int,
     *     by_method: array<string, array{count: int, amount: int}>,
     *     by_day: list<array{date: string, count: int, amount: int}>,
     *     by_fee_type: list<array{fee_type_id: int, code: string, name: string, amount: int}>
     * }
     */
    public function handle(FinanceReportFilter $filter): array
    {
        $byMethod = array_fill_keys(Payment::METHODS, ['count' => 0, 'amount' => 0]);

        $methodRows = $filter->paymentQuery()
            ->toBase()
            ->selectRaw('payments.method, COUNT(*) AS payment_count, SUM(payments.amount) AS amount')
            ->groupBy('payments.method')
            ->get();

        foreach ($methodRows as $row) {
            $byMethod[$row->method] ??= ['count' => 0, 'amount' => 0];
            $byMethod[$row->method]['count'] += (int) $row->payment_count;
            $byMethod[$row->method]['amount'] += (int) $row->amount;
        }

        $dayRows = $filter->paymentQuery()
            ->toBase()
            ->selectRaw('payments.paid_on, COUNT(*) AS payment_count, SUM(payments.amount) AS amount')
            ->groupBy('payments.paid_on')
            ->get();

        $byDay = [];

        foreach ($dayRows as $row) {
            // Kolom date bisa tersimpan dengan atau tanpa jam; kunci dinormalkan di PHP.
            $date = CarbonImmutable::parse($row->paid_on)->toDateString();
            $byDay[$date] ??= ['date' => $date, 'count' => 0, 'amount' => 0];
            $byDay[$date]['count'] += (int) $row->payment_count;
            $byDay[$date]['amount'] += (int) $row->amount;
        }

        ksort($byDay);

        $byFeeType = DB::table('payment_allocations')
            ->join('invoice_items', 'invoice_items.id', '=', 'payment_allocations.invoice_item_id')
            ->join('fee_types', 'fee_types.id', '=', 'invoice_items.fee_type_id')
            ->whereIn('payment_allocations.payment_id', $filter->paymentQuery()->select('payments.id'))
            ->selectRaw('fee_types.id AS fee_type_id, fee_types.code, fee_types.name, SUM(payment_allocations.amount) AS amount')
            ->groupBy('fee_types.id', 'fee_types.code', 'fee_types.name', 'fee_types.priority')
            ->orderBy('fee_types.priority')
            ->orderBy('fee_types.id')
            ->get()
            ->map(fn (object $row): array => [
                'fee_type_id' => (int) $row->fee_type_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'amount' => (int) $row->amount,
            ])
            ->all();

        return [
            'total' => array_sum(array_column($byMethod, 'amount')),
            'payment_count' => array_sum(array_column($byMethod, 'count')),
            'by_method' => $byMethod,
            'by_day' => array_values($byDay),
            'by_fee_type' => $byFeeType,
        ];
    }
}

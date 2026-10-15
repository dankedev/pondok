<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Santri dengan tunggakan terbesar per hari ini (F008).
 *
 * Tunggakan adalah akumulasi semua periode: rentang periode filter diabaikan,
 * filter jenjang dan kelompok tagihan tetap berlaku. Tagihan pendaftar tidak
 * pernah ikut. Santri diarsipkan atau tidak aktif tetap tampil.
 */
class ListTopArrears
{
    /**
     * @param  int|null  $limit  `null` = semua baris (dipakai ekspor CSV).
     * @return list<array{student_id: int, nis: ?string, name: string, unit_name: ?string, is_archived: bool, overdue_amount: int, overdue_invoice_count: int, oldest_period: string}>
     */
    public function handle(FinanceReportFilter $filter, ?int $limit = 10, ?CarbonInterface $asOf = null): array
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Limit tunggakan minimal 1.');
        }

        $asOfDate = ($asOf !== null ? CarbonImmutable::instance($asOf) : CarbonImmutable::today(config('app.timezone')))->toDateString();
        $remaining = SummarizeInvoices::REMAINING_SQL;

        $rows = $filter->invoiceOwnerQuery()
            ->whereNotNull('invoices.student_id')
            ->whereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->where('invoices.due_on', '<', $asOfDate)
            ->toBase()
            ->selectRaw("invoices.student_id, SUM({$remaining}) AS overdue_amount, COUNT(*) AS overdue_invoice_count, MIN(invoices.period) AS oldest_period")
            ->groupBy('invoices.student_id')
            ->orderByRaw("SUM({$remaining}) DESC")
            ->orderBy('invoices.student_id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Cakupan sudah dijamin query tagihan; santri diarsipkan ikut dimuat.
        $students = Student::withoutGlobalScopes()
            ->with('unit')
            ->whereKey($rows->pluck('student_id')->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function (object $row) use ($students): array {
            $student = $students->get((int) $row->student_id);

            return [
                'student_id' => (int) $row->student_id,
                'nis' => $student?->nis,
                'name' => (string) $student?->name,
                'unit_name' => $student?->unit?->name,
                'is_archived' => (bool) $student?->trashed(),
                'overdue_amount' => (int) $row->overdue_amount,
                'overdue_invoice_count' => (int) $row->overdue_invoice_count,
                'oldest_period' => CarbonImmutable::parse($row->oldest_period)->format('Y-m'),
            ];
        })->values()->all();
    }
}

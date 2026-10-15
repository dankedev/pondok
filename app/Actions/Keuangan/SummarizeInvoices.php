<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ringkasan tagihan untuk laporan keuangan (F008) dan widget dashboard (F018):
 * ditagihkan, terbayar, sisa, menunggak, dan rasio koleksi per bulan dan total.
 *
 * Himpunan: tagihan non-`batal` dalam rentang periode filter. "Terbayar" adalah
 * `paid_total` saat laporan dibaca. Menunggak = sisa tagihan `belum`/`sebagian`
 * dengan jatuh tempo sebelum `$asOf` (sama dengan `Invoice::isOverdue()`).
 * Jumlah query tetap (dua), tidak bergantung jumlah data.
 */
class SummarizeInvoices
{
    /** Sisa per tagihan, tidak pernah negatif (aman untuk kolom unsigned MySQL). */
    public const REMAINING_SQL = 'CASE WHEN invoices.total > invoices.paid_total THEN invoices.total - invoices.paid_total ELSE 0 END';

    private const REPORTED_STATUSES = [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID];

    /**
     * @return array{
     *     filter: array{period_from: string, period_to: string, unit_id: ?int, billing_group: ?string},
     *     as_of: string,
     *     totals: array{invoice_count: int, billed: int, paid: int, outstanding: int, overdue: int, not_yet_due: int, overdue_invoice_count: int, overdue_student_count: int, collection_rate: ?int, by_status: array<string, array{count: int, total: int, paid: int}>},
     *     periods: list<array{period: string, invoice_count: int, billed: int, paid: int, outstanding: int, overdue: int, collection_rate: ?int}>
     * }
     */
    public function handle(FinanceReportFilter $filter, ?CarbonInterface $asOf = null): array
    {
        $asOfDate = ($asOf !== null ? CarbonImmutable::instance($asOf) : CarbonImmutable::today(config('app.timezone')))->toDateString();
        $overdueCondition = "invoices.due_on < ? AND invoices.status IN ('".Invoice::STATUS_UNPAID."', '".Invoice::STATUS_PARTIAL."')";
        $remaining = self::REMAINING_SQL;

        $rows = $filter->invoiceQuery()
            ->toBase()
            ->selectRaw(
                "invoices.period, invoices.status, COUNT(*) AS invoice_count, SUM(invoices.total) AS billed, SUM(invoices.paid_total) AS paid, SUM({$remaining}) AS outstanding, "
                ."SUM(CASE WHEN {$overdueCondition} THEN {$remaining} ELSE 0 END) AS overdue, "
                ."SUM(CASE WHEN {$overdueCondition} THEN 1 ELSE 0 END) AS overdue_invoice_count",
                [$asOfDate, $asOfDate],
            )
            ->groupBy('invoices.period', 'invoices.status')
            ->get();

        $overdueStudentCount = (int) $filter->invoiceQuery()
            ->whereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->where('invoices.due_on', '<', $asOfDate)
            ->whereNotNull('invoices.student_id')
            ->toBase()
            ->distinct()
            ->count('invoices.student_id');

        $periods = [];

        foreach ($filter->months() as $month) {
            $periods[$month->format('Y-m')] = ['period' => $month->format('Y-m'), 'invoice_count' => 0, 'billed' => 0, 'paid' => 0, 'outstanding' => 0, 'overdue' => 0];
        }

        $byStatus = array_fill_keys(self::REPORTED_STATUSES, ['count' => 0, 'total' => 0, 'paid' => 0]);
        $overdueInvoiceCount = 0;

        foreach ($rows as $row) {
            // Kolom date bisa tersimpan dengan atau tanpa jam; kunci dinormalkan di PHP.
            $key = CarbonImmutable::parse($row->period)->format('Y-m');

            if (! isset($periods[$key])) {
                continue;
            }

            foreach (['invoice_count', 'billed', 'paid', 'outstanding', 'overdue'] as $metric) {
                $periods[$key][$metric] += (int) $row->{$metric};
            }

            if (isset($byStatus[$row->status])) {
                $byStatus[$row->status]['count'] += (int) $row->invoice_count;
                $byStatus[$row->status]['total'] += (int) $row->billed;
                $byStatus[$row->status]['paid'] += (int) $row->paid;
            }

            $overdueInvoiceCount += (int) $row->overdue_invoice_count;
        }

        $periods = array_values(array_map(
            fn (array $period): array => [...$period, 'collection_rate' => self::collectionRate($period['billed'], $period['paid'])],
            $periods,
        ));

        $sum = fn (string $metric): int => array_sum(array_column($periods, $metric));
        $outstanding = $sum('outstanding');
        $overdue = $sum('overdue');

        return [
            'filter' => $filter->toArray(),
            'as_of' => $asOfDate,
            'totals' => [
                'invoice_count' => $sum('invoice_count'),
                'billed' => $sum('billed'),
                'paid' => $sum('paid'),
                'outstanding' => $outstanding,
                'overdue' => $overdue,
                'not_yet_due' => $outstanding - $overdue,
                'overdue_invoice_count' => $overdueInvoiceCount,
                'overdue_student_count' => $overdueStudentCount,
                'collection_rate' => self::collectionRate($sum('billed'), $sum('paid')),
                'by_status' => $byStatus,
            ],
            'periods' => $periods,
        ];
    }

    /**
     * Persen bulat ke bawah; `null` bila tidak ada yang ditagihkan.
     */
    public static function collectionRate(int $billed, int $paid): ?int
    {
        return $billed > 0 ? intdiv($paid * 100, $billed) : null;
    }
}

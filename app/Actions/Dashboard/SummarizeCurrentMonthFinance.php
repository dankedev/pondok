<?php

namespace App\Actions\Dashboard;

use App\Actions\Keuangan\FinanceReportFilter;
use App\Actions\Keuangan\SummarizeCashReceipts;
use App\Actions\Keuangan\SummarizeInvoices;
use Carbon\CarbonInterface;

/**
 * Keuangan bulan berjalan untuk dashboard (F018).
 *
 * Tidak menghitung ulang apa pun: tagihan dari `SummarizeInvoices` dan kas dari
 * `SummarizeCashReceipts` (F008) dengan filter bulan berjalan zona
 * `config('app.timezone')`. Cakupan jenjang ikut global scope `Invoice`/`Payment`.
 */
class SummarizeCurrentMonthFinance
{
    public function __construct(
        private SummarizeInvoices $invoices,
        private SummarizeCashReceipts $cashReceipts,
    ) {}

    /**
     * @return array{period: string, invoice_count: int, billed: int, paid: int, collection_rate: ?int, overdue: int, overdue_student_count: int, cash_received: int, payment_count: int}
     */
    public function handle(?CarbonInterface $asOf = null): array
    {
        $filter = FinanceReportFilter::currentMonth();
        $totals = $this->invoices->handle($filter, $asOf)['totals'];
        $cash = $this->cashReceipts->handle($filter);

        return [
            'period' => $filter->periodFrom->format('Y-m'),
            'invoice_count' => $totals['invoice_count'],
            'billed' => $totals['billed'],
            'paid' => $totals['paid'],
            'collection_rate' => $totals['collection_rate'],
            'overdue' => $totals['overdue'],
            'overdue_student_count' => $totals['overdue_student_count'],
            'cash_received' => $cash['total'],
            'payment_count' => $cash['payment_count'],
        ];
    }
}

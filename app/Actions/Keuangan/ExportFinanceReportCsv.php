<?php

namespace App\Actions\Keuangan;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor satu bagian laporan keuangan (F008) sebagai CSV: UTF-8 dengan BOM,
 * pemisah `;` (Excel berlokal Indonesia), uang bilangan bulat polos, tanggal
 * `Y-m-d`, periode `Y-m`. Sel teks yang bisa dibaca sebagai rumus diberi
 * awalan `'`. Setiap unduhan dicatat di activity log `keuangan` karena berkas
 * memuat data pribadi santri.
 */
class ExportFinanceReportCsv
{
    public const SECTIONS = ['ringkasan', 'tunggakan', 'subsidi', 'kas'];

    public function __construct(
        private readonly SummarizeInvoices $summarizeInvoices,
        private readonly ListTopArrears $listTopArrears,
        private readonly SummarizeScholarshipSubsidies $summarizeSubsidies,
        private readonly SummarizeCashReceipts $summarizeCash,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(FinanceReportFilter $filter, string $section, User $user): StreamedResponse
    {
        ['rows' => $rows, 'data_rows' => $dataRows] = $this->build($filter, $section);

        activity('keuangan')
            ->causedBy($user)
            ->event('exported')
            ->withProperties([
                'section' => $section,
                'filter' => $filter->toArray(),
                'rows' => $dataRows,
            ])
            ->log(__('pondok.finance_report.activity_exported'));

        $filename = sprintf('laporan-keuangan-%s-%s-%s.csv', $section, $filter->periodFrom->format('Y-m'), $filter->periodTo->format('Y-m'));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Baris CSV termasuk header (dan baris kosong pemisah subbagian).
     *
     * @return list<list<string|int|null>>
     *
     * @throws ValidationException
     */
    public function rows(FinanceReportFilter $filter, string $section): array
    {
        return $this->build($filter, $section)['rows'];
    }

    /**
     * @return array{rows: list<list<string|int|null>>, data_rows: int}
     */
    private function build(FinanceReportFilter $filter, string $section): array
    {
        $blocks = match ($section) {
            'ringkasan' => $this->summaryBlocks($filter),
            'tunggakan' => $this->arrearsBlocks($filter),
            'subsidi' => $this->subsidyBlocks($filter),
            'kas' => $this->cashBlocks($filter),
            default => throw ValidationException::withMessages([
                'section' => __('pondok.finance_report.errors.section_invalid'),
            ]),
        };

        $rows = [];
        $dataRows = 0;

        foreach ($blocks as $index => $block) {
            if ($index > 0) {
                $rows[] = [];
            }

            if ($block['header'] !== []) {
                $rows[] = array_map(fn (string $key): string => $key === '' ? '' : __("pondok.finance_report.csv.{$key}"), $block['header']);
            }

            foreach ($block['rows'] as $row) {
                $rows[] = array_map(self::sanitize(...), $row);
                $dataRows++;
            }
        }

        return ['rows' => $rows, 'data_rows' => $dataRows];
    }

    /**
     * @return list<array{header: list<string>, rows: list<list<string|int|null>>}>
     */
    private function summaryBlocks(FinanceReportFilter $filter): array
    {
        $summary = $this->summarizeInvoices->handle($filter);
        $totals = $summary['totals'];

        $rows = array_map(
            fn (array $period): array => [$period['period'], $period['invoice_count'], $period['billed'], $period['paid'], $period['outstanding'], $period['overdue'], $period['collection_rate']],
            $summary['periods'],
        );
        $rows[] = [__('pondok.finance_report.total'), $totals['invoice_count'], $totals['billed'], $totals['paid'], $totals['outstanding'], $totals['overdue'], $totals['collection_rate']];

        return [[
            'header' => ['period', 'invoice_count', 'billed', 'paid', 'outstanding', 'overdue', 'collection_rate'],
            'rows' => $rows,
        ]];
    }

    /**
     * Semua santri menunggak, tanpa batas baris.
     *
     * @return list<array{header: list<string>, rows: list<list<string|int|null>>}>
     */
    private function arrearsBlocks(FinanceReportFilter $filter): array
    {
        return [[
            'header' => ['nis', 'name', 'unit', 'archived', 'invoice_count', 'oldest_period', 'overdue_amount'],
            'rows' => array_map(fn (array $arrear): array => [
                $arrear['nis'],
                $arrear['name'],
                $arrear['unit_name'],
                __($arrear['is_archived'] ? 'pondok.finance_report.yes' : 'pondok.finance_report.no'),
                $arrear['overdue_invoice_count'],
                $arrear['oldest_period'],
                $arrear['overdue_amount'],
            ], $this->listTopArrears->handle($filter, null)),
        ]];
    }

    /**
     * @return list<array{header: list<string>, rows: list<list<string|int|null>>}>
     */
    private function subsidyBlocks(FinanceReportFilter $filter): array
    {
        $subsidies = $this->summarizeSubsidies->handle($filter);
        $noSponsor = __('pondok.finance_report.no_sponsor');

        $programs = array_map(
            fn (array $program): array => [$program['name'], $program['sponsor'] ?? $noSponsor, $program['beneficiary_count'], $program['item_count'], $program['subsidy']],
            $subsidies['programs'],
        );
        $programs[] = [__('pondok.finance_report.total'), null, null, array_sum(array_column($subsidies['programs'], 'item_count')), $subsidies['total_subsidy']];

        $relief = $subsidies['non_scholarship_relief'];

        return [
            ['header' => ['program', 'sponsor', 'beneficiary_count', 'item_count', 'subsidy'], 'rows' => $programs],
            ['header' => ['sponsor', 'program_count', 'beneficiary_count', '', 'subsidy'], 'rows' => array_map(
                fn (array $sponsor): array => [$sponsor['sponsor'] ?? $noSponsor, $sponsor['program_count'], $sponsor['beneficiary_count'], null, $sponsor['subsidy']],
                $subsidies['sponsors'],
            )],
            ['header' => [], 'rows' => [[__('pondok.finance_report.non_scholarship'), null, null, $relief['item_count'], $relief['amount']]]],
        ];
    }

    /**
     * @return list<array{header: list<string>, rows: list<list<string|int|null>>}>
     */
    private function cashBlocks(FinanceReportFilter $filter): array
    {
        $cash = $this->summarizeCash->handle($filter);

        return [
            ['header' => ['date', 'payment_count', 'amount'], 'rows' => array_map(
                fn (array $day): array => [$day['date'], $day['count'], $day['amount']],
                $cash['by_day'],
            )],
            ['header' => ['method', 'payment_count', 'amount'], 'rows' => array_map(
                fn (string $method, array $row): array => [__("pondok.payment.methods.{$method}"), $row['count'], $row['amount']],
                array_keys($cash['by_method']),
                $cash['by_method'],
            )],
            ['header' => ['fee_type', 'code', 'amount'], 'rows' => array_map(
                fn (array $feeType): array => [$feeType['name'], $feeType['code'], $feeType['amount']],
                $cash['by_fee_type'],
            )],
            ['header' => [], 'rows' => [[__('pondok.finance_report.total'), $cash['payment_count'], $cash['total']]]],
        ];
    }

    /**
     * Cegah formula injection: teks yang diawali karakter rumus diberi awalan `'`.
     */
    private static function sanitize(string|int|null $cell): string|int|null
    {
        if (is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$cell;
        }

        return $cell;
    }
}

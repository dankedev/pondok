<?php

namespace App\Actions\Keuangan;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subsidi beasiswa per program dan per donatur, ditambah keringanan
 * non-beasiswa, pada tagihan non-`batal` dalam rentang periode (F008).
 *
 * Nominal dibaca dari snapshot `invoice_items.discount_amount`, sehingga
 * perubahan tarif, override, atau program setelah tagihan dibuat tidak
 * mengubah angka. Item tanpa keringanan tidak dihitung. Sponsor dikelompokkan
 * dengan nilai kolom apa adanya (`SaveScholarship` sudah men-trim saat simpan).
 */
class SummarizeScholarshipSubsidies
{
    /**
     * @return array{
     *     programs: list<array{scholarship_id: int, name: string, sponsor: ?string, subsidy: int, item_count: int, beneficiary_count: int}>,
     *     sponsors: list<array{sponsor: ?string, subsidy: int, program_count: int, beneficiary_count: int}>,
     *     non_scholarship_relief: array{amount: int, item_count: int},
     *     total_subsidy: int
     * }
     */
    public function handle(FinanceReportFilter $filter): array
    {
        $programs = $this->scholarshipItems($filter)
            ->selectRaw('scholarships.id AS scholarship_id, scholarships.name, scholarships.sponsor, SUM(invoice_items.discount_amount) AS subsidy, COUNT(*) AS item_count, COUNT(DISTINCT invoices.student_id) AS beneficiary_count')
            ->groupBy('scholarships.id', 'scholarships.name', 'scholarships.sponsor')
            ->orderByRaw('SUM(invoice_items.discount_amount) DESC')
            ->orderBy('scholarships.name')
            ->get()
            ->map(fn (object $row): array => [
                'scholarship_id' => (int) $row->scholarship_id,
                'name' => (string) $row->name,
                'sponsor' => $row->sponsor,
                'subsidy' => (int) $row->subsidy,
                'item_count' => (int) $row->item_count,
                'beneficiary_count' => (int) $row->beneficiary_count,
            ])
            ->all();

        $sponsors = $this->scholarshipItems($filter)
            ->selectRaw('scholarships.sponsor, SUM(invoice_items.discount_amount) AS subsidy, COUNT(DISTINCT scholarships.id) AS program_count, COUNT(DISTINCT invoices.student_id) AS beneficiary_count')
            ->groupBy('scholarships.sponsor')
            ->get()
            ->map(fn (object $row): array => [
                'sponsor' => $row->sponsor,
                'subsidy' => (int) $row->subsidy,
                'program_count' => (int) $row->program_count,
                'beneficiary_count' => (int) $row->beneficiary_count,
            ])
            // Urutan NULL berbeda antar database, jadi diurutkan di PHP: subsidi turun, nama naik, tanpa donatur terakhir.
            ->sort(fn (array $a, array $b): int => [$b['subsidy'], $a['sponsor'] === null, $a['sponsor']] <=> [$a['subsidy'], $b['sponsor'] === null, $b['sponsor']])
            ->values()
            ->all();

        $relief = $this->overrideItems($filter)
            ->whereNull('fee_overrides.scholarship_id')
            ->selectRaw('SUM(invoice_items.discount_amount) AS amount, COUNT(*) AS item_count')
            ->first();

        return [
            'programs' => $programs,
            'sponsors' => $sponsors,
            'non_scholarship_relief' => [
                'amount' => (int) ($relief->amount ?? 0),
                'item_count' => (int) ($relief->item_count ?? 0),
            ],
            'total_subsidy' => array_sum(array_column($programs, 'subsidy')),
        ];
    }

    /**
     * Item bertanda keringanan pada tagihan dalam filter (cakupan jenjang lewat subquery `Invoice`).
     */
    private function overrideItems(FinanceReportFilter $filter): Builder
    {
        return DB::table('invoice_items')
            ->join('fee_overrides', 'fee_overrides.id', '=', 'invoice_items.fee_override_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoice_items.invoice_id', $filter->invoiceQuery()->select('invoices.id'));
    }

    private function scholarshipItems(FinanceReportFilter $filter): Builder
    {
        return $this->overrideItems($filter)
            ->join('scholarships', 'scholarships.id', '=', 'fee_overrides.scholarship_id');
    }
}

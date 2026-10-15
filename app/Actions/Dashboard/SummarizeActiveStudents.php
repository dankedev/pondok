<?php

namespace App\Actions\Dashboard;

use App\Models\Cohort;
use App\Models\Student;
use App\Models\Unit;

/**
 * Santri aktif per jenjang dan angkatan untuk dashboard (F018).
 *
 * Berangkat dari `Student::query()` sehingga cakupan jenjang (`unit_user`) dan
 * santri diarsipkan (soft delete) tersaring otomatis. Semua jenjang dalam
 * cakupan tampil walau 0; angkatan tanpa santri aktif tidak ditampilkan.
 * Paling banyak tiga query, tidak bergantung jumlah data.
 */
class SummarizeActiveStudents
{
    /**
     * @return array{
     *     total: int,
     *     units: list<array{unit_id: int, name: string, total: int, cohorts: list<array{cohort_id: int, name: string, total: int}>}>
     * }
     */
    public function handle(): array
    {
        $rows = Student::query()
            ->active()
            ->toBase()
            ->selectRaw('unit_id, cohort_id, COUNT(*) AS total')
            ->groupBy('unit_id', 'cohort_id')
            ->get();

        /** @var array<int, array<int, int>> $counts unit_id => cohort_id => total */
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->unit_id][(int) $row->cohort_id] = ($counts[(int) $row->unit_id][(int) $row->cohort_id] ?? 0) + (int) $row->total;
        }

        $cohortIds = $rows->pluck('cohort_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $cohorts = $cohortIds === []
            ? collect()
            : Cohort::query()
                ->whereKey($cohortIds)
                ->orderByDesc('entry_year')
                ->orderBy('name')
                ->get(['id', 'unit_id', 'name', 'entry_year']);

        $units = Unit::query()
            ->accessible()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name']);

        $summary = [];

        foreach ($units as $unit) {
            $unitCohorts = [];

            foreach ($cohorts as $cohort) {
                $count = $counts[$unit->id][$cohort->id] ?? 0;

                if ($count > 0) {
                    $unitCohorts[] = ['cohort_id' => $cohort->id, 'name' => $cohort->name, 'total' => $count];
                }
            }

            $summary[] = [
                'unit_id' => $unit->id,
                'name' => $unit->name,
                'total' => array_sum($counts[$unit->id] ?? []),
                'cohorts' => $unitCohorts,
            ];
        }

        return [
            'total' => (int) $rows->sum('total'),
            'units' => $summary,
        ];
    }
}

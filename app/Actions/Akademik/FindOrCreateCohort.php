<?php

namespace App\Actions\Akademik;

use App\Models\Cohort;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Angkatan jenjang untuk tahun masuk, dibuat ("Angkatan 2027") bila belum ada.
 * Angkatan yang sudah ada dipakai apa adanya.
 */
class FindOrCreateCohort
{
    /**
     * @throws UniqueConstraintViolationException angkatan dibuat bersamaan oleh proses lain
     */
    public function handle(Unit $unit, int $entryYear): Cohort
    {
        return Cohort::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'entry_year' => $entryYear],
            ['name' => __('pondok.cohort.default_name', ['year' => $entryYear])],
        );
    }
}

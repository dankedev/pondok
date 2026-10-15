<?php

namespace App\Actions\Core;

use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;

/**
 * Menjadikan satu tahun ajaran aktif dan menonaktifkan yang lain.
 */
class ActivateAcademicYear
{
    public function handle(AcademicYear $academicYear): AcademicYear
    {
        DB::transaction(function () use ($academicYear): void {
            AcademicYear::query()
                ->whereKeyNot($academicYear->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $academicYear->forceFill(['is_active' => true])->save();
        });

        return $academicYear;
    }
}

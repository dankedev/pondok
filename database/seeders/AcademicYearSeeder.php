<?php

namespace Database\Seeders;

use App\Actions\Core\ActivateAcademicYear;
use App\Models\AcademicYear;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    public function run(ActivateAcademicYear $activate): void
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026/2027'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'],
        );

        if (AcademicYear::current() === null) {
            $activate->handle($year);
        }
    }
}

<?php

namespace App\Http\Controllers\Akademik;

use App\Actions\Akademik\BuildDiniyahReportCard;
use App\Actions\Akademik\RenderDiniyahReport;
use App\Filament\Pages\DiniyahGradeEntry;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Cetak rapor diniyah satu santri (F027). Butuh `view-diniyah-report`, dan rombel santri pada tahun itu
 * harus dalam `Classroom::visibleTo` (wali kelas hanya rombelnya, selain itu cakupan jenjang). Santri di
 * luar cakupan jenjang tidak ditemukan lewat binding (404); semester selain 1/2, tanpa enrollment, atau
 * tanpa nilai dan catatan = 404.
 */
class PrintDiniyahReport extends Controller
{
    public function __invoke(Request $request, Student $student, AcademicYear $year, int $semester, BuildDiniyahReportCard $build, RenderDiniyahReport $render): View
    {
        abort_unless(in_array($semester, [1, 2], true), 404);
        abort_unless($request->user()->can('view-diniyah-report'), 403);

        $card = $build->handle($student, $year, $semester);

        abort_unless($card['classroom'] === null || Classroom::visibleTo($request->user())->whereKey($card['classroom']->id)->exists(), 403);

        return $render->handle($card, DiniyahGradeEntry::getUrl());
    }
}

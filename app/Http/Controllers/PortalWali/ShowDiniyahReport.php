<?php

namespace App\Http\Controllers\PortalWali;

use App\Actions\Akademik\BuildDiniyahReportCard;
use App\Actions\Akademik\RenderDiniyahReport;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Rapor diniyah anak di portal wali (F027), halaman cetak yang sama dengan admin. Santri bukan milik
 * wali, tahun ajaran tidak ada, semester selain 1/2, tanpa enrollment, atau tanpa nilai = 404.
 */
class ShowDiniyahReport extends Controller
{
    public function __invoke(Request $request, int $student, int $year, int $semester, BuildDiniyahReportCard $build, RenderDiniyahReport $render): View
    {
        abort_unless(in_array($semester, [1, 2], true), 404);

        $student = $request->user()->portalStudent($student);
        $year = AcademicYear::query()->findOrFail($year);

        return $render->handle($build->handle($student, $year, $semester), route('portal-wali.students.show', $student->id));
    }
}

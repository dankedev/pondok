<?php

namespace App\Http\Controllers\PortalWali;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Beranda portal wali (F079): kartu per santri milik wali login, dengan sisa tagihan
 * non-batal bila Keuangan aktif. Data hanya lewat `User::portal*()`.
 */
class ShowHome extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $students = $user->portalStudentsWithSummary()->orderBy('name')->get();

        // Satu query per santri lewat `portalInvoices()`; wali hanya punya sedikit santri.
        $outstanding = Module::Keuangan->isEnabled()
            ? $students->mapWithKeys(fn (Student $student): array => [
                $student->id => $user->portalInvoices($student)
                    ->get(['id', 'total', 'paid_total'])
                    ->sum(fn (Invoice $invoice): int => $invoice->remainingAmount()),
            ])->all()
            : null;

        return view('portal-wali.home', ['students' => $students, 'outstanding' => $outstanding]);
    }
}

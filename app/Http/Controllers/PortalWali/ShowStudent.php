<?php

namespace App\Http\Controllers\PortalWali;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rincian santri portal wali (F079): ringkasan, tagihan non-batal berhalaman (Keuangan aktif),
 * dan dokumen terkini non-rahasia (Dokumen aktif). Santri bukan milik wali → 404.
 */
class ShowStudent extends Controller
{
    public function __invoke(Request $request, int $student): View
    {
        $user = $request->user();
        $student = $user->portalStudentsWithSummary()->findOrFail($student);

        return view('portal-wali.student', [
            'student' => $student,
            'invoices' => Module::Keuangan->isEnabled()
                ? $user->portalInvoices($student)->orderByDesc('period')->orderByDesc('id')->paginate(10)
                : null,
            'documents' => Module::Dokumen->isEnabled()
                ? $user->portalDocuments()->where('documentable_id', $student->id)->with('documentType')->latest('id')->get()
                : null,
        ]);
    }
}

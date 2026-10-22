<?php

namespace App\Http\Controllers\PortalWali;

use App\Http\Controllers\Controller;
use App\Models\Scopes\UnitScope;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rincian tagihan portal wali (F079): item, sisa, dan pembayaran `diterima`.
 * Tagihan `batal` atau milik santri lain → 404; route mati bersama saklar Keuangan.
 */
class ShowInvoice extends Controller
{
    public function __invoke(Request $request, int $invoice): View
    {
        $user = $request->user();
        $invoice = $user->portalInvoice($invoice)->load([
            'items.feeType',
            'student' => fn (Relation $relation) => $relation->withoutGlobalScope(UnitScope::class),
        ]);

        return view('portal-wali.invoice', [
            'invoice' => $invoice,
            'payments' => $user->portalPayments($invoice)->orderBy('paid_on')->orderBy('id')->get(),
        ]);
    }
}

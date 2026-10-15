<?php

namespace App\Http\Controllers\Keuangan;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Halaman cetak kuitansi (HTML mandiri A5, tanpa aset eksternal). Pembayaran
 * `batal` tetap bisa dicetak dengan tanda "DIBATALKAN".
 */
class ShowPaymentReceipt extends Controller
{
    public function __invoke(Payment $payment): View
    {
        Gate::authorize('view', $payment);

        $payment->load(['invoice.student.unit', 'invoice.student.guardians', 'allocations.invoiceItem', 'receiver']);

        $invoice = $payment->invoice;
        $student = $invoice?->student;
        // Kontak tagihan dengan id wali terkecil bila lebih dari satu; tanpa kontak, nama santri.
        $billingContact = $student?->guardians
            ->filter(fn ($guardian): bool => (bool) $guardian->pivot->is_billing_contact)
            ->sortBy('id')
            ->first();
        $logoPath = Setting::get('school.logo_path');

        return view('keuangan.receipt', [
            'payment' => $payment,
            'invoice' => $invoice,
            'student' => $student,
            'receivedFrom' => $billingContact?->name ?? $student?->name,
            'period' => $invoice?->period !== null ? InvoiceResource::periodLabel($invoice->period) : null,
            'school' => [
                'name' => Setting::get('school.name', config('app.name')),
                'address' => Setting::get('school.address'),
                'phone' => Setting::get('school.phone'),
                'logo' => filled($logoPath) && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->url($logoPath) : null,
            ],
            'printedAt' => now('Asia/Jakarta'),
        ]);
    }
}

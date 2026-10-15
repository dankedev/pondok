<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\CancelPayment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
    $this->user = keuanganUser('bendahara');
    $this->invoice = bayarInvoice($this->f, tagihanStudent($this->f, resident: true));
});

/**
 * Invarian F007: terbayar per item dan per tagihan sama dengan jumlah pembayaran diterima.
 */
function bayarAssertInvariants(Invoice $invoice): void
{
    $fresh = Invoice::withoutUnitScope()->findOrFail($invoice->id);
    $received = Payment::query()->where('invoice_id', $invoice->id)->where('status', 'diterima');
    $sumReceived = (int) (clone $received)->sum('amount');

    foreach (InvoiceItem::query()->where('invoice_id', $invoice->id)->get() as $item) {
        $allocated = (int) PaymentAllocation::query()
            ->where('invoice_item_id', $item->id)
            ->whereIn('payment_id', (clone $received)->select('id'))
            ->sum('amount');

        expect($item->paid_amount)->toBe($allocated)->and($item->paid_amount)->toBeLessThanOrEqual($item->amount);
    }

    foreach ((clone $received)->get() as $payment) {
        expect((int) $payment->allocations()->sum('amount'))->toBe($payment->amount);
    }

    $copy = clone $fresh;
    $copy->syncStatus();

    expect($fresh->paid_total)->toBe($sumReceived)
        ->and((int) InvoiceItem::query()->where('invoice_id', $invoice->id)->sum('paid_amount'))->toBe($sumReceived)
        ->and($fresh->paid_total)->toBeLessThanOrEqual($fresh->total)
        ->and($fresh->status)->toBe($copy->status);
}

it('AC-22: cancels a payment and reverses its allocations', function () {
    Storage::fake('private');
    $payment = bayarRecord($this->invoice, 500000, ['method' => 'transfer', 'proof' => UploadedFile::fake()->image('bukti.jpg')]);
    $path = $payment->fresh()->proof_path;

    $result = app(CancelPayment::class)->handle($payment, $this->user, '  salah input  ');

    $stored = Payment::query()->findOrFail($payment->id);

    expect($result)->toBeInstanceOf(Payment::class)
        ->and($result->status)->toBe('batal')
        ->and($stored->status)->toBe('batal')
        ->and($stored->cancelled_at)->not->toBeNull()
        ->and($stored->cancelled_by)->toBe($this->user->id)
        ->and($stored->cancel_reason)->toBe('salah input')
        ->and($stored->receipt_no)->toBe('KW-202610-00001')
        ->and($stored->amount)->toBe(500000)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(2)
        ->and(bayarAllocationsByCode($stored))->toBe(['MAKAN' => 400000, 'ASRAMA' => 100000])
        ->and(bayarPaidByCode($this->invoice))->toEqual(['SPP' => 0, 'ASRAMA' => 0, 'MAKAN' => 0])
        ->and(Storage::disk('private')->exists($path))->toBeTrue();

    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect($invoice->paid_total)->toBe(0)->and($invoice->status)->toBe('belum');
});

it('AC-23: keeps other payments when cancelling one in the middle', function () {
    $p1 = bayarRecord($this->invoice, 500000);
    $p2 = bayarRecord($this->invoice, 500000);

    app(CancelPayment::class)->handle($p1, $this->user, 'Salah');

    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect(bayarPaidByCode($this->invoice))->toEqual(['SPP' => 300000, 'ASRAMA' => 200000, 'MAKAN' => 0])
        ->and($invoice->paid_total)->toBe(500000)
        ->and($invoice->status)->toBe('sebagian')
        ->and(bayarAllocationsByCode($p2))->toBe(['ASRAMA' => 200000, 'SPP' => 300000]);

    $p3 = bayarRecord($this->invoice, 400000);

    expect(bayarAllocationsByCode($p3))->toBe(['MAKAN' => 400000]);
    bayarAssertInvariants($this->invoice);
});

it('AC-24: recomputes invoice status after cancellation', function () {
    $p1 = bayarRecord($this->invoice, 700000);
    $p2 = bayarRecord($this->invoice, 500000);

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('lunas');

    app(CancelPayment::class)->handle($p2, $this->user, 'Salah');

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('sebagian');

    app(CancelPayment::class)->handle($p1, $this->user, 'Salah juga');

    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect($invoice->status)->toBe('belum')->and($invoice->paid_total)->toBe(0);

    $cancelled = app(CancelInvoice::class)->handle($invoice, $this->user, 'Salah tagih');

    expect($cancelled->status)->toBe('batal');
});

it('AC-24: turns a single settled payment back to unpaid', function () {
    $payment = bayarRecord($this->invoice, 1200000);

    app(CancelPayment::class)->handle($payment, $this->user, 'Salah');

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('belum');
});

it('AC-25: rejects cancelling a cancelled payment', function () {
    $payment = bayarRecord($this->invoice, 500000);
    app(CancelPayment::class)->handle($payment, $this->user, 'Salah');
    $cancelledAt = $payment->fresh()->cancelled_at;

    bayarAssertRejected(fn () => app(CancelPayment::class)->handle($payment->fresh(), $this->user, 'Lagi'), 'already_cancelled', 'payment');

    expect($payment->fresh()->cancel_reason)->toBe('Salah')
        ->and($payment->fresh()->cancelled_at->eq($cancelledAt))->toBeTrue();
    bayarAssertInvariants($this->invoice);
});

it('AC-25: rejects a stale instance of an already cancelled payment', function () {
    $payment = bayarRecord($this->invoice, 500000);
    $stale = Payment::query()->find($payment->id);
    app(CancelPayment::class)->handle(Payment::query()->find($payment->id), $this->user, 'Salah');

    bayarAssertRejected(fn () => app(CancelPayment::class)->handle($stale, $this->user, 'Lagi'), 'already_cancelled', 'payment');

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->paid_total)->toBe(0);
});

it('AC-25: requires a reason of at most 255 characters', function (string $reason) {
    $payment = bayarRecord($this->invoice, 500000);

    bayarAssertRejected(fn () => app(CancelPayment::class)->handle($payment, $this->user, $reason), 'reason_invalid', 'reason');

    expect($payment->fresh()->status)->toBe('diterima');
    bayarAssertInvariants($this->invoice);
})->with([
    'empty' => [''],
    'spaces only' => ['   '],
    'too long' => [str_repeat('a', 256)],
]);

it('AC-25: accepts a reason of exactly 255 characters', function () {
    $payment = bayarRecord($this->invoice, 500000);

    app(CancelPayment::class)->handle($payment, $this->user, str_repeat('a', 255));

    expect($payment->fresh()->status)->toBe('batal');
});

it('AC-26: logs exactly one activity per cancellation', function () {
    $payment = bayarRecord($this->invoice, 500000);
    Activity::query()->delete();

    app(CancelPayment::class)->handle($payment, $this->user, 'Salah input');

    $activity = Activity::query()->sole();

    expect($activity->log_name)->toBe('keuangan')
        ->and($activity->event)->toBe('updated')
        ->and($activity->subject_type)->toBe($payment->getMorphClass())
        ->and($activity->subject_id)->toBe($payment->id)
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['old']['status'])->toBe('diterima')
        ->and($activity->properties['attributes']['status'])->toBe('batal')
        ->and($activity->properties['reason'])->toBe('Salah input')
        ->and($activity->properties['invoice_id'])->toBe($this->invoice->id)
        ->and($activity->properties['invoice_status']['old'])->toBe('sebagian')
        ->and($activity->properties['invoice_status']['new'])->toBe('belum');
});

it('AC-26: leaves no activity when the cancellation is rejected', function () {
    $payment = bayarRecord($this->invoice, 500000);
    Activity::query()->delete();

    expect(fn () => app(CancelPayment::class)->handle($payment, $this->user, ' '))->toThrow(ValidationException::class)
        ->and(Activity::query()->count())->toBe(0);
});

it('AC-28: keeps paid totals equal to active allocations', function () {
    $first = bayarRecord($this->invoice, 300000);
    bayarAssertInvariants($this->invoice);
    $second = bayarRecord($this->invoice, 400000);
    bayarAssertInvariants($this->invoice);
    app(CancelPayment::class)->handle($first, $this->user, 'Salah');
    bayarAssertInvariants($this->invoice);
    $third = bayarRecord($this->invoice, 500000);
    bayarAssertInvariants($this->invoice);
    app(CancelPayment::class)->handle($third, $this->user, 'Salah lagi');
    bayarAssertInvariants($this->invoice);

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->paid_total)->toBe(400000)
        ->and($second->fresh()->status)->toBe('diterima')
        ->and(Payment::query()->where('status', 'batal')->count())->toBe(2);
});

<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->user = keuanganUser('bendahara');
});

/**
 * Tagihan belum bayar dengan dua item.
 *
 * @param  array<string, mixed>  $attributes
 */
function cancelInvoiceFixture(array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create(['total' => 900000, ...$attributes]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'base_amount' => 500000, 'discount_amount' => 0, 'amount' => 500000]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'base_amount' => 400000, 'discount_amount' => 0, 'amount' => 400000]);

    return $invoice;
}

it('AC-36: cancels an unpaid invoice with exactly one activity row', function () {
    $invoice = cancelInvoiceFixture();
    Activity::query()->delete();

    $result = app(CancelInvoice::class)->handle($invoice, $this->user, '  Salah tagih  ');

    $activities = Activity::query()->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->get();

    expect($result)->toBeInstanceOf(Invoice::class)
        ->and($result->status)->toBe('batal')
        ->and($invoice->fresh()->status)->toBe('batal')
        ->and($invoice->fresh()->total)->toBe(900000)
        ->and($invoice->items()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe(1)
        ->and($activities)->toHaveCount(1);

    $activity = $activities->sole();

    expect($activity->log_name)->toBe('keuangan')
        ->and($activity->event)->toBe('updated')
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['old']['status'])->toBe('belum')
        ->and($activity->properties['attributes']['status'])->toBe('batal')
        ->and($activity->properties['reason'])->toBe('Salah tagih');
});

it('AC-36: marks the active marker as null after cancelling', function () {
    $invoice = cancelInvoiceFixture();

    app(CancelInvoice::class)->handle($invoice, $this->user, 'Salah tagih');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('active_marker'))->toBeNull();
});

it('AC-37: rejects cancelling a cancelled invoice', function () {
    $invoice = cancelInvoiceFixture(['status' => 'batal']);

    tagihanAssertRejected(fn () => app(CancelInvoice::class)->handle($invoice, $this->user, 'Lagi'), 'already_cancelled');

    expect($invoice->fresh()->status)->toBe('batal');
});

it('AC-37: rejects cancelling an invoice with payments', function (string $status) {
    $invoice = cancelInvoiceFixture(['status' => $status, 'paid_total' => 200000]);

    tagihanAssertRejected(fn () => app(CancelInvoice::class)->handle($invoice, $this->user, 'Batal'), 'has_payments');

    expect($invoice->fresh()->status)->toBe($status);
})->with(['sebagian', 'lunas']);

it('AC-37: requires a reason of at most 255 characters', function (string $reason) {
    $invoice = cancelInvoiceFixture();

    try {
        app(CancelInvoice::class)->handle($invoice, $this->user, $reason);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('reason');
    }

    expect($invoice->fresh()->status)->toBe('belum');
})->with([
    'empty' => [''],
    'spaces only' => ['   '],
    'too long' => [str_repeat('a', 256)],
]);

it('AC-37: leaves no activity when the cancellation is rejected', function () {
    $invoice = cancelInvoiceFixture();
    Activity::query()->delete();

    expect(fn () => app(CancelInvoice::class)->handle($invoice, $this->user, ' '))->toThrow(ValidationException::class)
        ->and(Activity::query()->count())->toBe(0);
});

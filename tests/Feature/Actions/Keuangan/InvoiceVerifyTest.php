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

it('VERIFY: rejects cancelling through a stale instance that was already cancelled', function () {
    $invoice = Invoice::factory()->create(['total' => 500000]);
    $stale = Invoice::query()->findOrFail($invoice->id);
    app(CancelInvoice::class)->handle($invoice, $this->user, 'Pertama');
    Activity::query()->delete();

    expect(fn () => app(CancelInvoice::class)->handle($stale, $this->user, 'Kedua'))->toThrow(ValidationException::class)
        ->and(Activity::query()->count())->toBe(0);
});

it('VERIFY: refuses to cancel a stale instance when a payment landed in the meantime', function () {
    $invoice = Invoice::factory()->create(['total' => 500000]);
    $stale = Invoice::query()->findOrFail($invoice->id);
    DB::table('invoices')->where('id', $invoice->id)->update(['paid_total' => 200000, 'status' => 'sebagian']);

    expect(fn () => app(CancelInvoice::class)->handle($stale, $this->user, 'Terlambat'))->toThrow(ValidationException::class)
        ->and(Invoice::query()->findOrFail($invoice->id)->status)->toBe('sebagian');
});

it('VERIFY: never deletes invoice rows or items through generation and cancellation', function () {
    $f = tagihanFixture();
    tagihanStudent($f, resident: true);
    tagihanGenerate();
    $invoice = Invoice::withoutUnitScope()->sole();
    $items = InvoiceItem::query()->count();

    app(CancelInvoice::class)->handle($invoice, $this->user, 'Koreksi');
    tagihanGenerate();
    tagihanGenerate();

    expect(Invoice::withoutUnitScope()->count())->toBe(2)
        ->and(InvoiceItem::query()->count())->toBe($items * 2);
});

it('VERIFY: stores generated amounts as integers', function () {
    $f = tagihanFixture();
    tagihanStudent($f, resident: true);
    keringananOverride(['student_id' => Invoice::query()->count() + 1, 'fee_type_id' => $f->spp->id, 'mode' => 'persen', 'value' => 33, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    tagihanGenerate();

    $types = DB::table('invoices')->selectRaw('typeof(total) as a, typeof(paid_total) as b')->first();
    $itemTypes = DB::table('invoice_items')->selectRaw('typeof(base_amount) as a, typeof(discount_amount) as b, typeof(amount) as c')->distinct()->get();

    expect($types->a)->toBe('integer')->and($types->b)->toBe('integer')
        ->and($itemTypes)->toHaveCount(1)
        ->and($itemTypes->first()->a)->toBe('integer')->and($itemTypes->first()->c)->toBe('integer');
});

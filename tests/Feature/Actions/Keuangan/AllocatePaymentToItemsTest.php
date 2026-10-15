<?php

use App\Actions\Keuangan\AllocatePaymentToItems;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Item tagihan dengan jenis biaya berprioritas tertentu, `feeType` sudah dimuat.
 *
 * @param  array<string, mixed>  $attributes
 */
function allocateItem(Invoice $invoice, int $priority, int $amount, int $paid = 0, array $attributes = []): InvoiceItem
{
    $type = FeeType::factory()->monthly()->create(['priority' => $priority]);

    return InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'fee_type_id' => $type->id,
        'base_amount' => $amount,
        'discount_amount' => 0,
        'amount' => $amount,
        'paid_amount' => $paid,
        ...$attributes,
    ])->load('feeType');
}

/**
 * @param  list<InvoiceItem>  $items
 * @return Collection<int, InvoiceItem>
 */
function allocateCollection(array $items): Collection
{
    return collect($items);
}

it('AC-5: allocates to the lowest priority number first', function () {
    $invoice = Invoice::factory()->create(['total' => 1200000]);
    $spp = allocateItem($invoice, 3, 500000);
    $asrama = allocateItem($invoice, 2, 300000);
    $makan = allocateItem($invoice, 1, 400000);

    $result = app(AllocatePaymentToItems::class)->handle(allocateCollection([$spp, $asrama, $makan]), 500000);

    expect($result)->toBe([
        ['invoice_item_id' => $makan->id, 'amount' => 400000],
        ['invoice_item_id' => $asrama->id, 'amount' => 100000],
    ])->and(array_sum(array_column($result, 'amount')))->toBe(500000);
});

it('AC-5: runs without touching the database', function () {
    $invoice = Invoice::factory()->create(['total' => 900000]);
    $a = allocateItem($invoice, 1, 400000);
    $b = allocateItem($invoice, 2, 500000);
    DB::enableQueryLog();

    app(AllocatePaymentToItems::class)->handle(allocateCollection([$a, $b]), 450000);

    expect(DB::getQueryLog())->toBe([])
        ->and(InvoiceItem::query()->sum('paid_amount'))->toBe(0);
});

it('AC-5: continues after partly paid items', function () {
    $invoice = Invoice::factory()->create(['total' => 900000]);
    $a = allocateItem($invoice, 1, 400000, paid: 150000);
    $b = allocateItem($invoice, 2, 500000);

    $result = app(AllocatePaymentToItems::class)->handle(allocateCollection([$b, $a]), 300000);

    expect($result)->toBe([
        ['invoice_item_id' => $a->id, 'amount' => 250000],
        ['invoice_item_id' => $b->id, 'amount' => 50000],
    ]);
});

it('AC-8: breaks priority ties by item id and skips settled items', function () {
    $invoice = Invoice::factory()->create(['total' => 600000]);
    $type = FeeType::factory()->monthly()->create(['priority' => 5]);
    $settled = allocateItem($invoice, 5, 100000, paid: 100000);
    $free = allocateItem($invoice, 5, 0);
    $first = allocateItem($invoice, 5, 200000);
    $second = allocateItem($invoice, 5, 300000);

    expect($settled->id)->toBeLessThan($first->id)->and($first->id)->toBeLessThan($second->id);

    $result = app(AllocatePaymentToItems::class)->handle(allocateCollection([$second, $free, $first, $settled]), 250000);

    expect($result)->toBe([
        ['invoice_item_id' => $first->id, 'amount' => 200000],
        ['invoice_item_id' => $second->id, 'amount' => 50000],
    ])->and(collect($result)->pluck('amount')->contains(0))->toBeFalse()
        ->and($type->exists)->toBeTrue();
});

it('AC-10: refuses to allocate more than the items owe', function () {
    $invoice = Invoice::factory()->create(['total' => 500000]);
    $item = allocateItem($invoice, 1, 400000, paid: 100000);

    expect(fn () => app(AllocatePaymentToItems::class)->handle(allocateCollection([$item]), 300001))
        ->toThrow(LogicException::class);

    expect(app(AllocatePaymentToItems::class)->handle(allocateCollection([$item]), 300000))
        ->toBe([['invoice_item_id' => $item->id, 'amount' => 300000]]);
});

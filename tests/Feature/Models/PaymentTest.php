<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

it('AC-45: spells the amount in Indonesian', function (int $amount, string $words) {
    expect(Payment::factory()->make(['amount' => $amount])->amountInWords())->toBe($words);
})->with([
    'zero' => [0, 'Nol rupiah'],
    'eleven' => [11, 'Sebelas rupiah'],
    'twenty one' => [21, 'Dua puluh satu rupiah'],
    'one hundred' => [100, 'Seratus rupiah'],
    'one hundred eleven' => [111, 'Seratus sebelas rupiah'],
    'one thousand' => [1000, 'Seribu rupiah'],
    'two thousand' => [2000, 'Dua ribu rupiah'],
    'twelve thousand' => [12000, 'Dua belas ribu rupiah'],
    'five hundred thousand' => [500000, 'Lima ratus ribu rupiah'],
    'one hundred thousand' => [100000, 'Seratus ribu rupiah'],
    'one million' => [1000000, 'Satu juta rupiah'],
    'one million two hundred thousand' => [1200000, 'Satu juta dua ratus ribu rupiah'],
    'one million two fifty' => [1250000, 'Satu juta dua ratus lima puluh ribu rupiah'],
    'one million thousand five hundred' => [1001500, 'Satu juta seribu lima ratus rupiah'],
    'two million one' => [2000001, 'Dua juta satu rupiah'],
    'billion' => [1000000000, 'Satu miliar rupiah'],
]);

it('AC-45: spells the same way whatever the application locale is', function () {
    app()->setLocale('en');

    expect(Payment::factory()->make(['amount' => 1250000])->amountInWords())->toBe('Satu juta dua ratus lima puluh ribu rupiah');
});

it('F007: knows its status, labels and cancellability', function () {
    $received = Payment::factory()->make();
    $cancelled = Payment::factory()->cancelled()->make();

    expect(Payment::STATUS_RECEIVED)->toBe('diterima')
        ->and(Payment::STATUS_CANCELLED)->toBe('batal')
        ->and(Payment::MANUAL_METHODS)->toBe(['tunai', 'transfer'])
        ->and($received->isCancellable())->toBeTrue()
        ->and($cancelled->isCancellable())->toBeFalse()
        ->and($received->statusColor())->toBe('success')
        ->and($cancelled->statusColor())->toBe('danger')
        ->and($received->statusLabel())->toBe(__('pondok.payment.statuses.diterima'))
        ->and($cancelled->statusLabel())->toBe(__('pondok.payment.statuses.batal'))
        ->and(Payment::factory()->make(['method' => 'tunai'])->methodLabel())->toBe('Tunai')
        ->and(Payment::factory()->transfer()->make()->methodLabel())->toBe('Transfer');
});

it('F007: links payments to invoices, items and users', function () {
    $payment = Payment::factory()->create();
    $item = InvoiceItem::factory()->create(['invoice_id' => $payment->invoice_id]);
    PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'invoice_item_id' => $item->id, 'amount' => 1000]);
    $canceller = User::factory()->create();
    $payment->forceFill(['cancelled_by' => $canceller->id])->save();

    expect($payment->invoice->is($payment->invoice))->toBeTrue()
        ->and($payment->invoice->payments->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->allocations)->toHaveCount(1)
        ->and($item->allocations)->toHaveCount(1)
        ->and($payment->receiver->receivedPayments->pluck('id')->all())->toBe([$payment->id])
        ->and($canceller->cancelledPayments->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->canceller->is($canceller))->toBeTrue();
});

it('F007: computes the remaining amount of invoices and items', function () {
    $invoice = Invoice::factory()->create(['total' => 900000, 'paid_total' => 200000]);
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'base_amount' => 500000, 'discount_amount' => 0, 'amount' => 500000, 'paid_amount' => 200000]);
    $over = Invoice::factory()->create(['total' => 100, 'paid_total' => 300]);

    expect($invoice->remainingAmount())->toBe(700000)
        ->and($item->remainingAmount())->toBe(300000)
        ->and($over->remainingAmount())->toBe(0);
});

it('F007: scopes payments to the units of the user through the invoice', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = tagihanFixture();
    $sltp = tagihanInvoice(tagihanStudent($f), $f->year, ['billing_group' => 'A']);
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'B']);
    $applicant = Invoice::factory()->create(['student_id' => null, 'applicant_id' => 5, 'billing_group' => 'PSB']);
    $archivedStudent = tagihanStudent($f);
    $archived = tagihanInvoice($archivedStudent, $f->year, ['billing_group' => 'C']);
    $archivedStudent->delete();
    $forSltp = Payment::factory()->create(['invoice_id' => $sltp->id]);
    $forSlta = Payment::factory()->create(['invoice_id' => $slta->id]);
    $forApplicant = Payment::factory()->create(['invoice_id' => $applicant->id]);
    $forArchived = Payment::factory()->create(['invoice_id' => $archived->id]);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(Payment::query()->pluck('id')->sort()->values()->all())->toBe(collect([$forSltp, $forArchived])->pluck('id')->sort()->values()->all())
        ->and(Payment::withoutUnitScope()->count())->toBe(4)
        ->and(Payment::query()->whereKey($forSlta->id)->exists())->toBeFalse()
        ->and(Payment::query()->whereKey($forApplicant->id)->exists())->toBeFalse();

    $this->actingAs(keuanganUser('bendahara'));

    expect(Payment::query()->count())->toBe(4);
});

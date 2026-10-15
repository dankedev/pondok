<?php

use App\Models\AcademicYear;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Spatie\Activitylog\Models\Activity;

it('AC-38: syncs status from paid and total', function (int $total, int $paid, string $expected) {
    $invoice = Invoice::factory()->create(['total' => $total, 'paid_total' => $paid, 'status' => 'belum']);

    $invoice->syncStatus();

    expect($invoice->status)->toBe($expected);
})->with([
    'nothing paid' => [500000, 0, 'belum'],
    'partly paid' => [500000, 200000, 'sebagian'],
    'fully paid' => [500000, 500000, 'lunas'],
    'overpaid' => [500000, 600000, 'lunas'],
    'free invoice' => [0, 0, 'lunas'],
]);

it('AC-38: never changes a cancelled invoice when syncing', function (int $total, int $paid) {
    $invoice = Invoice::factory()->create(['total' => $total, 'paid_total' => $paid, 'status' => 'batal']);

    $invoice->syncStatus();

    expect($invoice->status)->toBe('batal');
})->with([[500000, 0], [500000, 500000], [0, 0]]);

it('AC-38: recalculates the total from stored items and syncs status', function () {
    $invoice = Invoice::factory()->create(['total' => 0, 'paid_total' => 0, 'status' => 'lunas']);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'base_amount' => 500000, 'discount_amount' => 0, 'amount' => 500000]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'base_amount' => 400000, 'discount_amount' => 100000, 'amount' => 300000]);

    $invoice->recalculateTotal();

    $fresh = $invoice->fresh();

    expect($fresh->total)->toBe(800000)->and($fresh->status)->toBe('belum');
});

it('AC-38: decides whether an invoice can be cancelled', function (string $status, int $paid, bool $expected) {
    expect(Invoice::factory()->create(['status' => $status, 'paid_total' => $paid, 'total' => 500000])->isCancellable())->toBe($expected);
})->with([
    'unpaid' => ['belum', 0, true],
    'partly paid' => ['sebagian', 100000, false],
    'paid' => ['lunas', 500000, false],
    'cancelled' => ['batal', 0, false],
]);

it('AC-50: computes overdue and the status presentation', function (string $status, string $due, string $color, string $label) {
    $this->travelTo('2026-10-15 09:00:00');
    $invoice = Invoice::factory()->create(['status' => $status, 'due_on' => $due, 'total' => 500000, 'paid_total' => $status === 'lunas' ? 500000 : 0]);

    expect($invoice->statusColor())->toBe($color)
        ->and($invoice->statusLabel())->toBe($label)
        ->and($invoice->isOverdue())->toBe($label === 'Lewat jatuh tempo');
})->with([
    'unpaid before due' => ['belum', '2026-10-20', 'gray', 'Belum bayar'],
    'unpaid on due day' => ['belum', '2026-10-15', 'gray', 'Belum bayar'],
    'unpaid after due' => ['belum', '2026-10-10', 'danger', 'Lewat jatuh tempo'],
    'partial before due' => ['sebagian', '2026-10-20', 'warning', 'Sebagian'],
    'partial after due' => ['sebagian', '2026-10-10', 'danger', 'Lewat jatuh tempo'],
    'paid after due' => ['lunas', '2026-10-10', 'success', 'Lunas'],
    'cancelled after due' => ['batal', '2026-10-10', 'danger', 'Batal'],
]);

it('AC-6: exposes status and rate source constants', function () {
    expect(Invoice::STATUS_UNPAID)->toBe('belum')
        ->and(Invoice::STATUS_PARTIAL)->toBe('sebagian')
        ->and(Invoice::STATUS_PAID)->toBe('lunas')
        ->and(Invoice::STATUS_CANCELLED)->toBe('batal')
        ->and(Invoice::STATUSES)->toBe(['belum', 'sebagian', 'lunas', 'batal'])
        ->and(InvoiceItem::RATE_SOURCE_COHORT)->toBe('angkatan')
        ->and(InvoiceItem::RATE_SOURCE_UNIT)->toBe('jenjang')
        ->and(InvoiceItem::RATE_SOURCE_MANUAL)->toBe('manual')
        ->and(InvoiceItem::RATE_SOURCES)->toBe(['angkatan', 'jenjang', 'manual']);
});

it('AC-6: casts dates and money', function () {
    $invoice = Invoice::factory()->create(['period' => CarbonImmutable::parse('2026-10-01')->startOfMonth(), 'due_on' => '2026-10-10', 'total' => 500000])->fresh();
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id])->fresh();

    expect($invoice->period->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_on->toDateString())->toBe('2026-10-10')
        ->and($invoice->total)->toBeInt()
        ->and($invoice->paid_total)->toBeInt()
        ->and($item->base_amount)->toBeInt()
        ->and($item->discount_amount)->toBeInt()
        ->and($item->amount)->toBeInt()
        ->and($item->paid_amount)->toBeInt();
});

it('AC-6: provides factories whose items keep the amount invariant', function () {
    $item = InvoiceItem::factory()->create();

    expect($item->base_amount - $item->discount_amount)->toBe($item->amount)
        ->and(Invoice::factory()->count(3)->create()->pluck('invoice_no')->unique())->toHaveCount(3)
        ->and(Invoice::factory()->cancelled()->create()->status)->toBe('batal')
        ->and(Invoice::factory()->state(['total' => 500000])->partiallyPaid(200000)->create())->status->toBe('sebagian')->paid_total->toBe(200000)
        ->and(Invoice::factory()->state(['total' => 500000])->paid()->create())->status->toBe('lunas')->paid_total->toBe(500000);
});

it('AC-6: relates invoices to students, years, generators and items', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['generated_by' => $user->id]);
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);
    $override = FeeOverride::factory()->create();
    $overridden = InvoiceItem::factory()->create(['fee_override_id' => $override->id]);

    expect($invoice->student)->toBeInstanceOf(Student::class)
        ->and($invoice->academicYear)->toBeInstanceOf(AcademicYear::class)
        ->and($invoice->generator->is($user))->toBeTrue()
        ->and($invoice->items->first()->is($item))->toBeTrue()
        ->and($item->invoice->is($invoice))->toBeTrue()
        ->and($item->feeType)->toBeInstanceOf(FeeType::class)
        ->and($overridden->feeOverride->is($override))->toBeTrue()
        ->and($invoice->student->invoices->first()->is($invoice))->toBeTrue()
        ->and($invoice->academicYear->invoices->first()->is($invoice))->toBeTrue()
        ->and($user->generatedInvoices->first()->is($invoice))->toBeTrue()
        ->and($item->feeType->invoiceItems->first()->is($item))->toBeTrue()
        ->and($override->invoiceItems->first()->is($overridden))->toBeTrue();
});

it('AC-36: logs only status changes on the keuangan log', function () {
    $invoice = Invoice::factory()->create(['total' => 500000]);
    Activity::query()->delete();

    $invoice->update(['total' => 600000]);

    expect(Activity::query()->count())->toBe(0);

    $invoice->status = 'sebagian';
    $invoice->save();

    $activity = Activity::query()->sole();

    expect($activity->log_name)->toBe('keuangan')
        ->and($activity->properties['attributes'])->toBe(['status' => 'sebagian'])
        ->and($activity->properties['old'])->toBe(['status' => 'belum']);
});

it('AC-46: scopes invoices to the units of the logged-in user through the student', function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class]);
    $f = tagihanFixture(withRates: false);
    $sltp = tagihanInvoice(tagihanStudent($f), $f->year);
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year);
    $archivedStudent = tagihanStudent($f);
    $archived = tagihanInvoice($archivedStudent, $f->year, ['billing_group' => 'LAIN']);
    $archivedStudent->delete();

    $scoped = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($scoped);

    expect(Invoice::query()->pluck('id')->sort()->values()->all())->toBe(collect([$sltp->id, $archived->id])->sort()->values()->all())
        ->and(Invoice::query()->whereKey($slta->id)->exists())->toBeFalse()
        ->and(Invoice::withoutUnitScope()->count())->toBe(3);

    $this->actingAs(keuanganUser('bendahara'));

    expect(Invoice::query()->count())->toBe(3);
});

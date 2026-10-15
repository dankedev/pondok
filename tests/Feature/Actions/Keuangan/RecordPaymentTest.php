<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\GenerateReceiptNumber;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
    $this->user = keuanganUser('bendahara');
    $this->student = tagihanStudent($this->f, resident: true);
    $this->invoice = bayarInvoice($this->f, $this->student);
});

/**
 * Tidak ada pembayaran, alokasi, atau perubahan terbayar sama sekali.
 */
function bayarAssertUntouched(Invoice $invoice, int $paidTotal = 0, string $status = 'belum'): void
{
    $fresh = Invoice::withoutUnitScope()->findOrFail($invoice->id);

    expect($fresh->paid_total)->toBe($paidTotal)
        ->and($fresh->status)->toBe($status)
        ->and(InvoiceItem::query()->where('invoice_id', $invoice->id)->sum('paid_amount'))->toBe($paidTotal);
}

it('AC-6: allocates instalments by fee type priority', function () {
    $first = bayarRecord($this->invoice, 500000);
    $second = bayarRecord($this->invoice, 500000);

    expect(bayarAllocationsByCode($first))->toBe(['MAKAN' => 400000, 'ASRAMA' => 100000])
        ->and(bayarAllocationsByCode($second))->toBe(['ASRAMA' => 200000, 'SPP' => 300000])
        ->and(bayarPaidByCode($this->invoice))->toEqual(['MAKAN' => 400000, 'ASRAMA' => 300000, 'SPP' => 300000]);

    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect($invoice->status)->toBe('sebagian')->and($invoice->paid_total)->toBe(1000000);

    $third = bayarRecord($this->invoice, 200000);

    expect(bayarAllocationsByCode($third))->toBe(['SPP' => 200000])
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('lunas');
});

it('AC-7: ignores item creation order', function () {
    $f = $this->f;
    $invoice = tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 1200000]);
    foreach ([[$f->spp, 500000], [$f->asrama, 300000], [$f->makan, 400000]] as [$type, $amount]) {
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $type->id, 'base_amount' => $amount, 'discount_amount' => 0, 'amount' => $amount]);
    }

    $payment = bayarRecord($invoice, 400000);

    expect(bayarAllocationsByCode($payment))->toBe(['MAKAN' => 400000])
        ->and(bayarPaidByCode($invoice))->toEqual(['SPP' => 0, 'ASRAMA' => 0, 'MAKAN' => 400000]);
});

it('AC-8: never writes zero allocations for free or settled items', function () {
    $f = $this->f;
    $invoice = tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 700000]);
    $free = FeeType::factory()->monthly()->create(['code' => 'GRATIS', 'priority' => 1]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $free->id, 'base_amount' => 100000, 'discount_amount' => 100000, 'amount' => 0]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->makan->id, 'base_amount' => 400000, 'discount_amount' => 0, 'amount' => 400000]);
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $f->spp->id, 'base_amount' => 300000, 'discount_amount' => 0, 'amount' => 300000]);

    bayarRecord($invoice, 400000);
    $second = bayarRecord($invoice, 100000);

    expect(bayarAllocationsByCode($second))->toBe(['SPP' => 100000])
        ->and(PaymentAllocation::query()->where('amount', 0)->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(2);
});

it('AC-9: reads priority at payment time', function () {
    $this->f->spp->update(['priority' => 0]);

    $payment = bayarRecord($this->invoice, 500000);

    expect(bayarAllocationsByCode($payment))->toBe(['SPP' => 500000]);
});

it('AC-11: settles an invoice with a full payment', function () {
    $payment = bayarRecord($this->invoice, 1200000, ['paid_on' => '2026-10-15'], $this->user);

    $stored = Payment::query()->findOrFail($payment->id);
    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect(Payment::query()->count())->toBe(1)
        ->and($stored->receipt_no)->toBe('KW-202610-00001')
        ->and($stored->amount)->toBe(1200000)
        ->and($stored->method)->toBe('tunai')
        ->and($stored->status)->toBe('diterima')
        ->and($stored->received_by)->toBe($this->user->id)
        ->and($stored->proof_path)->toBeNull()
        ->and($stored->paid_on->toDateString())->toBe('2026-10-15')
        ->and($stored->invoice_id)->toBe($this->invoice->id)
        ->and(PaymentAllocation::query()->count())->toBe(3)
        ->and(bayarPaidByCode($this->invoice))->toEqual(['SPP' => 500000, 'ASRAMA' => 300000, 'MAKAN' => 400000])
        ->and($invoice->paid_total)->toBe(1200000)
        ->and($invoice->status)->toBe('lunas')
        ->and($payment->relationLoaded('allocations'))->toBeTrue()
        ->and($this->invoice->paid_total)->toBe(1200000)
        ->and($this->invoice->status)->toBe('lunas');
});

it('AC-11: accepts a numeric string amount and a Carbon date', function () {
    $payment = bayarRecord($this->invoice, 0, ['amount' => '300000', 'paid_on' => CarbonImmutable::parse('2026-10-14')]);

    expect($payment->amount)->toBe(300000)
        ->and($payment->paid_on->toDateString())->toBe('2026-10-14');
});

it('AC-12: marks partial payment and locks the invoice', function () {
    bayarRecord($this->invoice, 500000);

    $invoice = Invoice::withoutUnitScope()->find($this->invoice->id);

    expect($invoice->status)->toBe('sebagian')->and($invoice->isCancellable())->toBeFalse();

    tagihanAssertRejected(fn () => app(CancelInvoice::class)->handle($invoice, $this->user, 'Salah'), 'has_payments');

    $f = $this->f;
    $extra = FeeType::factory()->monthly()->resident()->create(['code' => 'LAUNDRY', 'name' => 'Laundry', 'billing_group' => 'BULANAN', 'priority' => 4]);
    keuanganRate($extra, $f->year, $f->sltp, 100000);

    $result = tagihanGenerate();

    expect($result['skipped'])->toContain(['student_id' => $this->student->id, 'fee_type_id' => $extra->id, 'reason' => 'invoice_locked'])
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->total)->toBe(1200000);
});

it('AC-13: rejects paying more than the remaining amount', function () {
    bayarRecord($this->invoice, 500000);

    bayarAssertRejected(fn () => bayarRecord($this->invoice, 700001), 'exceeds_remaining', 'amount');

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(2);
    bayarAssertUntouched($this->invoice, 500000, 'sebagian');

    $exact = bayarRecord($this->invoice, 700000);

    expect($exact->amount)->toBe(700000)
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('lunas');
});

it('AC-13: mentions the remaining amount in rupiah format', function () {
    bayarRecord($this->invoice, 500000);

    try {
        bayarRecord($this->invoice, 800000);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain('Rp 700.000');
    }
});

it('AC-14: rejects paid or cancelled invoices', function (Closure $make, string $reason) {
    $invoice = $make($this->f);
    $payments = Payment::query()->count();
    $allocations = PaymentAllocation::query()->count();

    bayarAssertRejected(fn () => bayarRecord($invoice, 1000), $reason, 'invoice');

    expect(Payment::query()->count())->toBe($payments)->and(PaymentAllocation::query()->count())->toBe($allocations);
})->with([
    'paid in full' => [function (object $f): Invoice {
        $invoice = bayarInvoice($f);
        bayarRecord($invoice, 1200000);

        return $invoice;
    }, 'already_paid'],
    'zero total invoice' => [fn (object $f): Invoice => tagihanInvoice(tagihanStudent($f), $f->year, ['billing_group' => 'NOL', 'total' => 0, 'status' => 'lunas']), 'already_paid'],
    'cancelled invoice' => [fn (object $f): Invoice => tagihanInvoice(tagihanStudent($f), $f->year, ['billing_group' => 'BATAL', 'total' => 500000, 'status' => 'batal']), 'invoice_cancelled'],
]);

it('AC-15: validates payment input', function (array $data, string $reason, string $field) {
    bayarAssertRejected(fn () => bayarRecord($this->invoice, 100000, $data), $reason, $field);

    expect(Payment::query()->count())->toBe(0)->and(PaymentAllocation::query()->count())->toBe(0);
    bayarAssertUntouched($this->invoice);
})->with([
    'zero amount' => [['amount' => 0], 'amount_invalid', 'amount'],
    'negative amount' => [['amount' => -1], 'amount_invalid', 'amount'],
    'decimal string amount' => [['amount' => '1.5'], 'amount_invalid', 'amount'],
    'text amount' => [['amount' => 'abc'], 'amount_invalid', 'amount'],
    'gateway method' => [['method' => 'gateway'], 'method_invalid', 'method'],
    'unknown method' => [['method' => 'cek'], 'method_invalid', 'method'],
    'future date' => [['paid_on' => '2026-10-16'], 'paid_on_future', 'paid_on'],
    'invalid date' => [['paid_on' => 'bukan-tanggal'], 'paid_on_invalid', 'paid_on'],
    'long note' => [['note' => str_repeat('a', 1001)], 'note_too_long', 'note'],
]);

it('AC-15: accepts a past date, trims the note and stores a blank note as null', function () {
    $past = bayarRecord($this->invoice, 100000, ['paid_on' => '2026-09-20', 'note' => '  titip lewat wali  ']);
    $blank = bayarRecord($this->invoice, 100000, ['note' => '   ']);
    $limit = bayarRecord($this->invoice, 100000, ['note' => str_repeat('a', 1000)]);

    expect($past->fresh()->paid_on->toDateString())->toBe('2026-09-20')
        ->and($past->fresh()->note)->toBe('titip lewat wali')
        ->and($blank->fresh()->note)->toBeNull()
        ->and(mb_strlen($limit->fresh()->note))->toBe(1000);
});

it('AC-15: judges the future date in the Asia/Jakarta timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:00:00', 'UTC'));

    $today = bayarRecord($this->invoice, 100000, ['paid_on' => '2026-10-16']);

    expect($today->fresh()->paid_on->toDateString())->toBe('2026-10-16');

    bayarAssertRejected(fn () => bayarRecord($this->invoice, 100000, ['paid_on' => '2026-10-17']), 'paid_on_future', 'paid_on');

    expect(Payment::query()->count())->toBe(1);
});

it('AC-16: validates against the locked invoice row', function () {
    $stale = Invoice::withoutUnitScope()->find($this->invoice->id);
    $other = Invoice::withoutUnitScope()->find($this->invoice->id);

    bayarRecord($stale, 1000000);

    bayarAssertRejected(fn () => bayarRecord($other, 500000), 'exceeds_remaining', 'amount');

    expect(Payment::query()->count())->toBe(1);
    bayarAssertUntouched($this->invoice, 1000000, 'sebagian');
});

it('AC-16: rejects a stale instance of a cancelled invoice', function () {
    $stale = Invoice::withoutUnitScope()->find($this->invoice->id);

    app(CancelInvoice::class)->handle(Invoice::withoutUnitScope()->find($this->invoice->id), $this->user, 'Salah tagih');

    bayarAssertRejected(fn () => bayarRecord($stale, 100000), 'invoice_cancelled', 'invoice');

    expect(Payment::query()->count())->toBe(0);
});

it('AC-17: stores transfer proof on the private disk', function () {
    Storage::fake('private');
    Storage::fake('public');

    $payment = bayarRecord($this->invoice, 500000, ['method' => 'transfer', 'proof' => UploadedFile::fake()->image('bukti.jpg')]);

    $stored = $payment->fresh();

    expect($stored->proof_path)->toStartWith('payment-proofs/2026/10/')
        ->and(Storage::disk('private')->exists($stored->proof_path))->toBeTrue()
        ->and(Storage::disk('private')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($stored->method)->toBe('transfer');
});

it('AC-17: accepts a transfer without proof', function () {
    Storage::fake('private');

    $payment = bayarRecord($this->invoice, 500000, ['method' => 'transfer']);

    expect($payment->fresh()->proof_path)->toBeNull()->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('AC-17: accepts pdf and png proofs up to 2 MB', function (string $name) {
    Storage::fake('private');

    $file = str_ends_with($name, 'pdf') ? UploadedFile::fake()->create($name, 2048, 'application/pdf') : UploadedFile::fake()->image($name);
    $payment = bayarRecord($this->invoice, 100000, ['method' => 'transfer', 'proof' => $file]);

    expect(Storage::disk('private')->exists($payment->fresh()->proof_path))->toBeTrue();
})->with(['bukti.pdf', 'bukti.png', 'bukti.jpeg']);

it('AC-17: rejects invalid proofs and leaves nothing on disk', function (string $method, Closure $file, string $reason) {
    Storage::fake('private');
    Storage::fake('public');

    bayarAssertRejected(fn () => bayarRecord($this->invoice, 500000, ['method' => $method, 'proof' => $file()]), $reason, 'proof');

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Payment::query()->count())->toBe(0);
    bayarAssertUntouched($this->invoice);
})->with([
    'cash with proof' => ['tunai', fn () => UploadedFile::fake()->image('bukti.jpg'), 'proof_not_allowed'],
    'gif' => ['transfer', fn () => UploadedFile::fake()->image('bukti.gif'), 'proof_invalid'],
    'txt' => ['transfer', fn () => UploadedFile::fake()->create('bukti.txt', 10, 'text/plain'), 'proof_invalid'],
    'php disguised as image name' => ['transfer', fn () => UploadedFile::fake()->create('bukti.php', 10, 'application/x-php'), 'proof_invalid'],
    'too large' => ['transfer', fn () => UploadedFile::fake()->create('bukti.pdf', 2049, 'application/pdf'), 'proof_invalid'],
]);

it('AC-18: removes the stored proof when recording fails', function () {
    Storage::fake('private');

    bayarAssertRejected(fn () => bayarRecord($this->invoice, 2000000, ['method' => 'transfer', 'proof' => UploadedFile::fake()->image('bukti.jpg')]), 'exceeds_remaining', 'amount');

    expect(Storage::disk('private')->allFiles())->toBe([])->and(Payment::query()->count())->toBe(0);
});

it('AC-19: numbers receipts per recording month', function () {
    $first = bayarRecord($this->invoice, 100000);
    $second = bayarRecord($this->invoice, 100000);

    expect($first->receipt_no)->toBe('KW-202610-00001')->and($second->receipt_no)->toBe('KW-202610-00002');
});

it('AC-19: continues after the highest number even when it belongs to a cancelled payment', function () {
    Payment::factory()->cancelled()->create(['receipt_no' => 'KW-202610-00007']);

    $payment = bayarRecord($this->invoice, 100000);

    expect($payment->receipt_no)->toBe('KW-202610-00008');
});

it('AC-19: restarts the sequence in a new month', function () {
    bayarRecord($this->invoice, 100000);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Jakarta'));

    $payment = bayarRecord($this->invoice, 100000);

    expect($payment->receipt_no)->toBe('KW-202611-00001');
});

it('AC-19: uses the recording month instead of the payment date', function () {
    $payment = bayarRecord($this->invoice, 100000, ['paid_on' => '2026-09-20']);

    expect($payment->receipt_no)->toStartWith('KW-202610-');
});

it('AC-19: uses the Jakarta month at the month boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:30:00', 'UTC'));

    $payment = bayarRecord($this->invoice, 100000, ['paid_on' => '2026-10-31']);

    expect($payment->receipt_no)->toBe('KW-202611-00001');
});

it('AC-19: counts payments of other units for a scoped user', function () {
    $f = $this->f;
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'X']);
    Payment::factory()->create(['invoice_id' => $slta->id, 'receipt_no' => 'KW-202610-00005']);
    $scoped = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($scoped);

    $payment = bayarRecord($this->invoice, 100000, by: $scoped);

    expect($payment->receipt_no)->toBe('KW-202610-00006');
});

it('AC-20: retries with a fresh counter on a receipt number clash', function () {
    Payment::factory()->create(['receipt_no' => 'KW-202610-00001']);
    $calls = 0;
    $this->partialMock(GenerateReceiptNumber::class, function (MockInterface $mock) use (&$calls) {
        $mock->shouldReceive('lastSequence')->twice()->andReturnUsing(function (CarbonInterface $month) use (&$calls): int {
            return ++$calls === 1 ? 0 : (new GenerateReceiptNumber)->lastSequence($month);
        });
    });

    $payment = bayarRecord($this->invoice, 500000);

    expect($payment->receipt_no)->toBe('KW-202610-00002')
        ->and($calls)->toBe(2)
        ->and(Payment::query()->count())->toBe(2)
        ->and(PaymentAllocation::query()->count())->toBe(2);
    bayarAssertUntouched($this->invoice, 500000, 'sebagian');
});

it('AC-20: stops after three receipt number clashes without leaving traces', function () {
    Storage::fake('private');
    Payment::factory()->create(['receipt_no' => 'KW-202610-00001']);
    $this->partialMock(GenerateReceiptNumber::class, fn (MockInterface $mock) => $mock->shouldReceive('lastSequence')->andReturn(0));
    $inserts = 0;
    DB::beforeExecuting(function (string $sql) use (&$inserts): void {
        if (str_starts_with(strtolower(ltrim($sql)), 'insert into "payments"')) {
            $inserts++;
        }
    });

    expect(fn () => bayarRecord($this->invoice, 500000, ['method' => 'transfer', 'proof' => UploadedFile::fake()->image('bukti.jpg')]))
        ->toThrow(RuntimeException::class, __('pondok.payment.errors.receipt_number_conflict'));

    expect($inserts)->toBe(3)
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
    bayarAssertUntouched($this->invoice);
});

it('AC-20: refuses sequences outside 1 to 99999', function () {
    $action = app(GenerateReceiptNumber::class);
    $month = CarbonImmutable::parse('2026-10-01');

    expect($action->format($month, 1))->toBe('KW-202610-00001')
        ->and($action->format($month, 99999))->toBe('KW-202610-99999')
        ->and(fn () => $action->format($month, 0))->toThrow(LogicException::class)
        ->and(fn () => $action->format($month, 100000))->toThrow(LogicException::class);
});

it('AC-21: logs exactly one activity per payment', function () {
    Activity::query()->delete();

    $payment = bayarRecord($this->invoice, 500000, ['method' => 'tunai', 'paid_on' => '2026-10-14'], $this->user);

    $activity = Activity::query()->sole();

    expect($activity->log_name)->toBe('keuangan')
        ->and($activity->event)->toBe('created')
        ->and($activity->subject_type)->toBe($payment->getMorphClass())
        ->and($activity->subject_id)->toBe($payment->id)
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['receipt_no'])->toBe('KW-202610-00001')
        ->and($activity->properties['invoice_no'])->toBe($this->invoice->invoice_no)
        ->and($activity->properties['amount'])->toBe(500000)
        ->and($activity->properties['method'])->toBe('tunai')
        ->and($activity->properties['paid_on'])->toBe('2026-10-14')
        ->and($activity->properties['invoice_status']['old'])->toBe('belum')
        ->and($activity->properties['invoice_status']['new'])->toBe('sebagian')
        ->and(collect($activity->properties['allocations'])->map(fn (array $row): array => [$row['fee_type_id'], $row['amount']])->all())
        ->toBe([[$this->f->makan->id, 400000], [$this->f->asrama->id, 100000]]);
});

it('AC-21: leaves no activity when the payment is rejected', function () {
    Activity::query()->delete();

    expect(fn () => bayarRecord($this->invoice, 9999999))->toThrow(ValidationException::class)
        ->and(Activity::query()->count())->toBe(0);
});

it('AC-11: keeps every money value an integer', function () {
    $payment = bayarRecord($this->invoice, 500000);

    expect($payment->amount)->toBeInt()
        ->and($payment->allocations->every(fn (PaymentAllocation $allocation): bool => is_int($allocation->amount)))->toBeTrue();
});

it('VERIFY: rejects amounts that are not plain positive integers', function (mixed $amount) {
    bayarAssertRejected(fn () => bayarRecord($this->invoice, 1, ['amount' => $amount]), 'amount_invalid', 'amount');

    expect(Payment::query()->count())->toBe(0);
})->with(['null' => [null], 'huge' => ['99999999999999999999'], 'leading zeros' => ['0500'], 'exponent' => ['1e5'], 'array' => [[5]], 'float' => [1.5]]);

it('VERIFY: never reduces what other payments allocated when recording concurrently stale invoices', function () {
    $a = Invoice::withoutUnitScope()->find($this->invoice->id);
    bayarRecord($a, 1200000);

    bayarAssertRejected(fn () => bayarRecord(Invoice::withoutUnitScope()->find($this->invoice->id), 1), 'already_paid', 'invoice');

    expect(Invoice::withoutUnitScope()->find($this->invoice->id)->paid_total)->toBe(1200000);
});

<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RoomAssignment;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

beforeEach(function () {
    $this->f = tagihanFixture();
});

it('AC-23: is idempotent', function () {
    $f = $this->f;
    tagihanStudent($f, resident: true);
    tagihanStudent($f);
    tagihanStudent($f, resident: true);

    tagihanGenerate();

    $numbers = Invoice::withoutUnitScope()->orderBy('id')->pluck('invoice_no')->all();
    $invoices = Invoice::withoutUnitScope()->count();
    $items = InvoiceItem::query()->count();

    $second = tagihanGenerate();

    expect($invoices)->toBe(3)
        ->and(Invoice::withoutUnitScope()->count())->toBe(3)
        ->and(InvoiceItem::query()->count())->toBe($items)
        ->and($second['created_invoices'])->toBe(0)
        ->and($second['added_items'])->toBe(0)
        ->and($second['existing_invoices'])->toBe(3)
        ->and($second['skipped'])->toBe([])
        ->and(Invoice::withoutUnitScope()->orderBy('id')->pluck('invoice_no')->all())->toBe($numbers);
});

it('AC-24: keeps item snapshots after a rate change', function () {
    $f = $this->f;
    $student = tagihanStudent($f);
    tagihanGenerate();

    FeeRate::query()->where('fee_type_id', $f->spp->id)->update(['amount' => 600000]);
    tagihanGenerate();

    $invoice = tagihanInvoiceFor($student);

    expect(tagihanItems($invoice)->sole()->amount)->toBe(500000)
        ->and($invoice->fresh()->total)->toBe(500000);

    tagihanGenerate('2026-11-01');

    expect(tagihanItems(tagihanInvoiceFor($student, period: '2026-11-01'))->sole()->amount)->toBe(600000);
});

it('AC-24: keeps the snapshot when a new rate row takes over', function () {
    $f = $this->f;
    $student = tagihanStudent($f);
    tagihanGenerate();

    keuanganRate($f->spp, $f->year, $f->sltp, 600000, $f->cohort);
    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($student))->sole()->amount)->toBe(500000);
});

it('AC-25: keeps items after override or residency changes', function () {
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();
    $before = tagihanItems(tagihanInvoiceFor($student))->map->only(['amount', 'base_amount', 'discount_amount', 'fee_override_id'])->all();

    keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'mode' => 'potongan', 'value' => 100000, 'valid_from' => '2026-07-01', 'valid_until' => null]);
    RoomAssignment::query()->where('student_id', $student->id)->update(['end_on' => '2026-10-02']);

    $result = tagihanGenerate();

    $invoice = tagihanInvoiceFor($student);

    expect($result['created_invoices'])->toBe(0)
        ->and($result['added_items'])->toBe(0)
        ->and(tagihanItems($invoice)->map->only(['amount', 'base_amount', 'discount_amount', 'fee_override_id'])->all())->toBe($before)
        ->and(tagihanItems($invoice))->toHaveCount(3)
        ->and($invoice->fresh()->total)->toBe(1200000);
});

it('AC-26: adds a newly resolvable item to an unpaid invoice', function () {
    $f = $this->f;
    FeeRate::query()->where('fee_type_id', $f->asrama->id)->delete();
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();
    $invoice = tagihanInvoiceFor($student);

    expect($invoice->total)->toBe(900000);

    keuanganRate($f->asrama, $f->year, $f->sltp, 300000);
    $result = tagihanGenerate();

    $invoice->refresh();

    expect($result['added_items'])->toBe(1)
        ->and($result['created_invoices'])->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(tagihanItems($invoice))->toHaveCount(3)
        ->and(tagihanItems($invoice)[$f->asrama->id]->amount)->toBe(300000)
        ->and($invoice->total)->toBe(1200000)
        ->and($invoice->status)->toBe('belum');
});

it('AC-27: leaves a paid invoice untouched and reports it as locked', function () {
    $f = $this->f;
    FeeRate::query()->where('fee_type_id', $f->asrama->id)->delete();
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();
    $invoice = tagihanInvoiceFor($student);
    $invoice->forceFill(['paid_total' => 200000, 'status' => 'sebagian'])->save();

    keuanganRate($f->asrama, $f->year, $f->sltp, 300000);
    $result = tagihanGenerate();

    $invoice->refresh();

    expect($result['added_items'])->toBe(0)
        ->and($result['skipped'])->toContain(['student_id' => $student->id, 'fee_type_id' => $f->asrama->id, 'reason' => 'invoice_locked'])
        ->and(tagihanItems($invoice))->toHaveCount(2)
        ->and($invoice->total)->toBe(900000)
        ->and($invoice->status)->toBe('sebagian')
        ->and($invoice->paid_total)->toBe(200000);
});

it('AC-28: bills a fee type once per period after its group changes', function () {
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();

    $f->spp->update(['billing_group' => 'SPP']);
    tagihanGenerate();

    $sppItems = InvoiceItem::query()->where('fee_type_id', $f->spp->id)->get();

    expect(Invoice::withoutUnitScope()->where('student_id', $student->id)->where('billing_group', 'SPP')->exists())->toBeFalse()
        ->and($sppItems)->toHaveCount(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(1);
});

it('AC-55: reissues a cancelled invoice with a new number', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();
    $old = tagihanInvoiceFor($student);
    app(CancelInvoice::class)->handle($old, keuanganUser('bendahara'), 'Salah tarif');
    FeeRate::query()->where('fee_type_id', $f->spp->id)->update(['amount' => 450000]);

    $result = tagihanGenerate();

    $new = tagihanInvoiceFor($student);
    $old->refresh();

    expect($result['created_invoices'])->toBe(1)
        ->and($new->id)->not->toBe($old->id)
        ->and($new->invoice_no)->toBe('INV-202610-00002')
        ->and($new->status)->toBe('belum')
        ->and(tagihanItems($new)[$f->spp->id]->amount)->toBe(450000)
        ->and($new->total)->toBe(1150000)
        ->and($old->status)->toBe('batal')
        ->and($old->invoice_no)->toBe('INV-202610-00001')
        ->and($old->total)->toBe(1200000)
        ->and($old->items()->count())->toBe(3)
        ->and($old->items()->where('fee_type_id', $f->spp->id)->value('amount'))->toBe(500000);

    $again = tagihanGenerate();

    expect($again['created_invoices'])->toBe(0)
        ->and($again['existing_invoices'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(2);
});

it('AC-58: ignores cancelled invoices when checking billed fee types', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);
    tagihanGenerate();
    app(CancelInvoice::class)->handle(tagihanInvoiceFor($student), keuanganUser('bendahara'), 'Koreksi grup');
    $f->spp->update(['billing_group' => 'SPP']);

    $result = tagihanGenerate();

    $spp = tagihanInvoiceFor($student, 'SPP');
    $bulanan = tagihanInvoiceFor($student, 'BULANAN');

    expect($result['created_invoices'])->toBe(2)
        ->and(tagihanItems($spp)->keys()->all())->toBe([$f->spp->id])
        ->and(tagihanItems($bulanan)->keys()->sort()->values()->all())->toBe(collect([$f->asrama->id, $f->makan->id])->sort()->values()->all())
        ->and(Invoice::withoutUnitScope()->where('status', 'batal')->count())->toBe(1);
});

it('AC-59: numbers invoices across units for a scoped user', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    $slta = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    tagihanInvoice($slta, $f->year, ['billing_group' => 'X', 'invoice_no' => 'INV-202610-00005']);
    tagihanInvoice($slta, $f->year, ['billing_group' => 'X', 'invoice_no' => 'INV-202610-00006', 'status' => 'batal']);
    $first = tagihanStudent($f);
    $second = tagihanStudent($f);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $result = tagihanGenerate();

    $numbers = Invoice::withoutUnitScope()->pluck('invoice_no');

    expect($result['created_invoices'])->toBe(2)
        ->and($numbers->unique())->toHaveCount(4)
        ->and(collect([tagihanInvoiceFor($first)->invoice_no, tagihanInvoiceFor($second)->invoice_no])->sort()->values()->all())
        ->toBe(['INV-202610-00007', 'INV-202610-00008']);
});

it('AC-60: retries with a fresh counter on a number clash', function () {
    $f = $this->f;
    $other = tagihanStudent($f, attributes: ['status' => 'lulus']);
    tagihanInvoice($other, $f->year, ['billing_group' => 'LAIN', 'invoice_no' => 'INV-202610-00001']);
    $student = tagihanStudent($f);
    $calls = 0;
    $this->partialMock(GenerateInvoiceNumber::class, function (MockInterface $mock) use (&$calls) {
        $mock->shouldReceive('lastSequence')->twice()->andReturnUsing(function (CarbonInterface $month) use (&$calls): int {
            return ++$calls === 1 ? 0 : (new GenerateInvoiceNumber)->lastSequence($month);
        });
    });

    $result = tagihanGenerate();

    $invoice = tagihanInvoiceFor($student);

    expect($invoice)->not->toBeNull()
        ->and($invoice->invoice_no)->toBe('INV-202610-00002')
        ->and($invoice->status)->toBe('belum')
        ->and(tagihanItems($invoice))->toHaveCount(1)
        ->and($result['created_invoices'])->toBe(1)
        ->and($result['existing_invoices'])->toBe(0)
        ->and($result['skipped'])->toBe([])
        ->and(Invoice::withoutUnitScope()->pluck('invoice_no')->unique())->toHaveCount(2);
});

it('AC-61: stops after three number clashes without leaving half-made invoices', function () {
    $f = $this->f;
    $other = tagihanStudent($f, attributes: ['status' => 'lulus']);
    tagihanInvoice($other, $f->year, ['billing_group' => 'LAIN', 'invoice_no' => 'INV-202610-00001']);
    $student = tagihanStudent($f);
    $this->partialMock(GenerateInvoiceNumber::class, fn (MockInterface $mock) => $mock->shouldReceive('lastSequence')->andReturn(0));
    $inserts = 0;
    DB::beforeExecuting(function (string $sql) use (&$inserts): void {
        if (str_starts_with(strtolower(ltrim($sql)), 'insert into "invoices"')) {
            $inserts++;
        }
    });

    expect(fn () => tagihanGenerate())->toThrow(RuntimeException::class, __('pondok.invoice.errors.number_conflict'));

    expect($inserts)->toBe(3)
        ->and(Invoice::withoutUnitScope()->where('student_id', $student->id)->exists())->toBeFalse()
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(0);

    $lock = Cache::lock('keuangan:generate-invoices:2026-10', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('AC-62: isolates a conflict to one billing group', function () {
    $f = $this->f;
    $f->spp->update(['billing_group' => 'SPP']);
    $f->asrama->update(['billing_group' => 'ASRAMA']);
    $f->makan->update(['billing_group' => 'ASRAMA']);
    $student = tagihanStudent($f, resident: true);
    $injected = false;
    $this->partialMock(ResolveFeeRate::class, function (MockInterface $mock) use (&$injected, $f, $student) {
        $mock->shouldReceive('handle')->andReturnUsing(function (...$arguments) use (&$injected, $f, $student) {
            if (! $injected) {
                $injected = true;
                tagihanInvoice($student, $f->year, ['billing_group' => 'ASRAMA', 'invoice_no' => 'INV-202610-00099']);
            }

            return (new ResolveFeeRate)->handle(...$arguments);
        });
    });

    $result = tagihanGenerate();

    $asrama = Invoice::withoutUnitScope()->where('student_id', $student->id)->where('billing_group', 'ASRAMA')->get();
    $spp = tagihanInvoiceFor($student, 'SPP');

    expect($injected)->toBeTrue()
        ->and($asrama)->toHaveCount(1)
        ->and($asrama->first()->invoice_no)->toBe('INV-202610-00099')
        ->and($spp)->not->toBeNull()
        ->and(tagihanItems($spp)->keys()->all())->toBe([$f->spp->id])
        ->and($result['created_invoices'])->toBe(1)
        ->and($result['existing_invoices'])->toBe(1)
        ->and(Invoice::withoutUnitScope()->pluck('invoice_no')->unique())->toHaveCount(2);
});

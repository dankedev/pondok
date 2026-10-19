<?php

use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\ImportOpeningBalanceRow;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->s = saldoFixture();
    $this->user = keuanganUser('bendahara');
    $this->actingAs($this->user);
});

function saldoImport(array $row = []): Invoice
{
    return app(ImportOpeningBalanceRow::class)->handle(saldoRow($row));
}

/**
 * Baris harus gagal dengan pesan lang `pondok.invoice.import.errors.<reason>`.
 */
function saldoAssertFails(Closure $callback, string $reason, string $label = ''): void
{
    $expected = __("pondok.invoice.import.errors.{$reason}");
    expect($expected)->not->toBe("pondok.invoice.import.errors.{$reason}");

    try {
        $callback();
    } catch (RowImportFailedException $exception) {
        expect($exception->getMessage())->toBe($expected, $label);

        return;
    }

    test()->fail("{$label}: RowImportFailedException diharapkan, tetapi tidak dilempar.");
}

it('AC-3: creates one unpaid SALDO_AWAL invoice with a manual item and no payments', function () {
    $invoice = saldoImport();
    $item = $invoice->items()->sole();
    $spp = FeeType::query()->where('code', 'SPP')->firstOrFail();

    expect(Invoice::GROUP_OPENING_BALANCE)->toBe('SALDO_AWAL')
        ->and(saldoInvoices())->toHaveCount(1)
        ->and($invoice->billing_group)->toBe('SALDO_AWAL')
        ->and($invoice->student_id)->toBe($this->s->student->id)
        ->and($invoice->applicant_id)->toBeNull()
        ->and($invoice->status)->toBe('belum')
        ->and($invoice->paid_total)->toBe(0)
        ->and($invoice->total)->toBe(1500000)
        ->and($invoice->period->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_on->toDateString())->toBe('2026-09-30')
        ->and($invoice->isOverdue())->toBeTrue()
        ->and($invoice->academic_year_id)->toBe($this->s->f->year->id)
        ->and($invoice->generated_by)->toBe($this->user->id)
        ->and($invoice->invoice_no)->toBe(app(GenerateInvoiceNumber::class)->format(CarbonImmutable::parse('2026-10-01'), 1))
        ->and($item->fee_type_id)->toBe($spp->id)
        ->and($item->base_amount)->toBe(1500000)
        ->and($item->amount)->toBe(1500000)
        ->and($item->discount_amount)->toBe(0)
        ->and($item->rate_source)->toBe('manual')
        ->and($item->fee_override_id)->toBeNull()
        ->and($item->description)->toBe(__('pondok.invoice.opening_balance_item', ['fee_type' => $spp->name]))
        ->and(Payment::query()->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0);
});

it('AC-3: takes the period from the Jakarta calendar month, not UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:30', 'UTC'));

    $invoice = saldoImport();

    expect($invoice->period->toDateString())->toBe('2026-11-01')
        ->and($invoice->due_on->toDateString())->toBe('2026-10-31');
});

it('AC-3: retries the invoice number after a collision, using the next number read', function () {
    $other = tagihanStudent($this->s->f, attributes: ['nis' => '2026002']);
    $taken = saldoImport(['nis' => '2026002'])->invoice_no;
    $calls = 0;

    $this->partialMock(GenerateInvoiceNumber::class, function (MockInterface $mock) use ($taken, &$calls) {
        $mock->shouldReceive('handle')->andReturnUsing(function () use ($taken, &$calls): string {
            return ++$calls === 1 ? $taken : 'INV-202610-90001';
        });
    });

    $invoice = saldoImport();

    expect($calls)->toBe(2)
        ->and($invoice->invoice_no)->toBe('INV-202610-90001')
        ->and(saldoInvoices($this->s->student))->toHaveCount(1)
        ->and(saldoInvoices($other))->toHaveCount(1);
});

it('AC-3: writes exactly one keuangan imported log per new invoice and per added item, without NIS or name', function () {
    Activity::query()->delete();

    $invoice = saldoImport();
    $logs = Activity::query()->where('log_name', 'keuangan')->get();

    expect($logs)->toHaveCount(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and($logs[0]->event)->toBe('imported')
        ->and($logs[0]->subject_type)->toBe($invoice->getMorphClass())
        ->and($logs[0]->subject_id)->toBe($invoice->id)
        ->and($logs[0]->causer_id)->toBe($this->user->id)
        ->and($logs[0]->properties['source'])->toBe('opening_balance')
        ->and($logs[0]->properties['total'])->toBe(1500000)
        ->and(json_encode($logs[0]->properties))->not->toContain('2026001')->not->toContain($this->s->student->name);

    saldoImport(['fee_type' => 'ASRAMA', 'amount' => '200000']);

    expect(Activity::query()->count())->toBe(2)
        ->and(Activity::query()->latest('id')->first())->event->toBe('imported');
});

it('AC-4: adds a second fee type of the same student to the same invoice and recalculates the total', function () {
    $first = saldoImport();
    $second = saldoImport(['fee_type' => 'ASRAMA', 'amount' => '250000']);

    expect($second->id)->toBe($first->id)
        ->and(saldoInvoices())->toHaveCount(1)
        ->and($first->items()->count())->toBe(2)
        ->and($first->fresh()->total)->toBe(1750000)
        ->and($first->fresh()->status)->toBe('belum');
});

it('AC-4: refuses to add an item once the opening invoice has a payment, leaving it unchanged', function () {
    $invoice = saldoImport();
    bayarRecord($invoice, 500000);

    saldoAssertFails(fn () => saldoImport(['fee_type' => 'ASRAMA', 'amount' => '250000']), 'invoice_paid');

    expect($invoice->fresh())->total->toBe(1500000)->paid_total->toBe(500000)->status->toBe('sebagian')
        ->and($invoice->items()->count())->toBe(1);
});

it('AC-5: rejects the same student and fee type again, even in a later month', function () {
    $invoice = saldoImport();

    saldoAssertFails(fn () => saldoImport(['amount' => '999']), 'already_imported', 'same month');

    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));

    saldoAssertFails(fn () => saldoImport(), 'already_imported', 'next month');

    expect(saldoInvoices())->toHaveCount(1)
        ->and($invoice->fresh()->total)->toBe(1500000)
        ->and($invoice->items()->count())->toBe(1);
});

it('AC-5: allows the same row again once the earlier opening invoice is cancelled', function () {
    $old = saldoImport();
    $old->forceFill(['status' => 'batal'])->save();

    $new = saldoImport();

    expect($new->id)->not->toBe($old->id)
        ->and($new->status)->toBe('belum')
        ->and($new->total)->toBe(1500000)
        ->and(Invoice::withoutUnitScope()->where('billing_group', 'SALDO_AWAL')->count())->toBe(2);
});

it('AC-6: accepts plain digit amounts, trimmed, up to 12 digits, stored as integers', function () {
    $cases = ['1' => '1', ' 1500000 ' => '1500000', '999999999999' => '999999999999'];
    $n = 10;

    foreach ($cases as $given => $stored) {
        $student = tagihanStudent($this->s->f, attributes: ['nis' => (string) (3000000 + $n++)]);
        $invoice = saldoImport(['nis' => $student->nis, 'amount' => (string) $given]);

        expect($invoice->total)->toBe((int) $stored, "nominal {$given}")
            ->and($invoice->items()->sole()->amount)->toBeInt();
    }
});

it('AC-6: rejects invalid amounts with an Indonesian reason and creates nothing', function () {
    $cases = ['0', '-5000', '1.500.000', '1,5', '1500000.50', 'Rp1500000', 'Rp 1.500.000', '1 500 000', 'seribu', '', '   ', '1000000000000', '00500', '+500'];

    foreach ($cases as $amount) {
        saldoAssertFails(fn () => saldoImport(['amount' => $amount]), 'amount_invalid', "nominal '{$amount}'");
    }

    expect(Invoice::withoutUnitScope()->count())->toBe(0)
        ->and(DB::table('invoice_items')->count())->toBe(0);
});

it('AC-7: fails clearly for unknown NIS, unknown or inactive fee type, and no active academic year', function () {
    $assertNothing = fn () => expect(Invoice::withoutUnitScope()->count())->toBe(0);

    saldoAssertFails(fn () => saldoImport(['nis' => '9999999']), 'student_unknown', 'NIS tidak ada');
    saldoAssertFails(fn () => saldoImport(['fee_type' => 'TIDAKADA']), 'fee_type_unknown', 'kode tidak ada');

    FeeType::query()->where('code', 'SPP')->update(['is_active' => false]);
    saldoAssertFails(fn () => saldoImport(), 'fee_type_unknown', 'jenis biaya nonaktif');
    FeeType::query()->where('code', 'SPP')->update(['is_active' => true]);

    AcademicYear::query()->update(['is_active' => false]);
    saldoAssertFails(fn () => saldoImport(), 'no_active_year', 'tanpa tahun ajaran aktif');

    $assertNothing();
});

it('AC-7: matches the fee type code case-insensitively and accepts every frequency and applies_to', function () {
    $pangkal = FeeType::factory()->create(['code' => 'PANGKAL', 'name' => 'Uang Pangkal', 'frequency' => 'saat_diterima', 'billing_group' => 'PANGKAL']);

    expect(saldoImport(['fee_type' => ' spp '])->items()->sole()->feeType->code)->toBe('SPP');

    $invoice = saldoImport(['fee_type' => 'pangkal', 'amount' => '3000000']);
    $nonResident = saldoImport(['fee_type' => 'MAKAN', 'amount' => '100000']);

    expect($invoice->id)->toBe($nonResident->id)
        ->and($invoice->items()->count())->toBe(3)
        ->and($invoice->fresh()->total)->toBe(4600000)
        ->and($invoice->billing_group)->toBe('SALDO_AWAL')
        ->and($pangkal->billing_group)->toBe('PANGKAL');
});

it('AC-8: accepts a student who has left and rejects a soft-deleted one', function () {
    $this->s->student->update(['status' => 'lulus']);

    expect(saldoImport()->student_id)->toBe($this->s->student->id);

    $other = tagihanStudent($this->s->f, attributes: ['nis' => '2026002']);
    $other->delete();

    saldoAssertFails(fn () => saldoImport(['nis' => '2026002']), 'student_unknown');
    expect(saldoInvoices($other))->toHaveCount(0);
});

it('AC-8: applies the unit scope to NIS lookup, and unrestricted users see every unit', function () {
    $slta = tagihanStudent($this->s->f, attributes: ['nis' => '2026003'], cohort: $this->s->f->cohortSlta);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    saldoAssertFails(fn () => saldoImport(['nis' => '2026003']), 'student_unknown');
    expect(saldoImport()->student_id)->toBe($this->s->student->id)
        ->and(saldoInvoices($slta))->toHaveCount(0);

    $this->actingAs(keuanganUser('bendahara'));

    expect(saldoImport(['nis' => '2026003'])->student_id)->toBe($slta->id);
});

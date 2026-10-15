<?php

use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('AC-22: rejects duplicate default rates through generated keys', function () {
    $rate = FeeRate::factory()->create();
    $attributes = $rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id', 'amount']);

    expect(fn () => FeeRate::factory()->create($attributes))->toThrow(UniqueConstraintViolationException::class)
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-22: rejects duplicate cohort rates through generated keys', function () {
    $cohort = Cohort::factory()->create();
    $rate = FeeRate::factory()->create(['unit_id' => $cohort->unit_id, 'cohort_id' => $cohort->id]);

    expect(fn () => FeeRate::factory()->create([
        ...$rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_id']),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-22: includes the wave key in the unique rate', function () {
    $rate = FeeRate::factory()->create(['admission_wave_id' => 7]);
    $same = $rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id']);

    expect(fn () => FeeRate::factory()->create([...$same, 'admission_wave_id' => 7]))->toThrow(UniqueConstraintViolationException::class);

    FeeRate::factory()->create([...$same, 'admission_wave_id' => null]);
    FeeRate::factory()->create([...$same, 'admission_wave_id' => 8]);

    expect(FeeRate::query()->count())->toBe(3);
});

it('AC-22: fills generated keys without the application writing them', function () {
    $cohort = Cohort::factory()->create();
    $dormitory = Dormitory::factory()->create();

    $plain = FeeRate::factory()->create()->refresh();
    $full = FeeRate::factory()->create(['unit_id' => $cohort->unit_id, 'cohort_id' => $cohort->id, 'dormitory_id' => $dormitory->id, 'admission_wave_id' => 7])->refresh();

    expect((int) $plain->cohort_key)->toBe(0)->and((int) $plain->dormitory_key)->toBe(0)->and((int) $plain->wave_key)->toBe(0)
        ->and((int) $full->cohort_key)->toBe($cohort->id)->and((int) $full->dormitory_key)->toBe($dormitory->id)->and((int) $full->wave_key)->toBe(7);
});

it('AC-23: restricts deleting records referenced by rates', function (Closure $referenced) {
    $record = $referenced(FeeRate::factory()->create());

    expect(fn () => $record->delete())->toThrow(QueryException::class)
        ->and($record::query()->whereKey($record->getKey())->exists())->toBeTrue();
})->with([
    'fee type' => [fn (FeeRate $rate): FeeType => $rate->feeType],
    'academic year' => [fn (FeeRate $rate): AcademicYear => $rate->effectiveFromYear],
    'unit' => [fn (FeeRate $rate): Unit => $rate->unit],
    'cohort' => [function (FeeRate $rate): Cohort {
        $cohort = Cohort::factory()->create(['unit_id' => $rate->unit_id]);
        $rate->update(['cohort_id' => $cohort->id]);

        return $cohort;
    }],
    'dormitory' => [function (FeeRate $rate): Dormitory {
        $dormitory = Dormitory::factory()->create();
        $rate->update(['dormitory_id' => $dormitory->id]);

        return $dormitory;
    }],
]);

it('AC-23: rejects a duplicate fee type code', function () {
    FeeType::factory()->create(['code' => 'SPP']);

    FeeType::factory()->create(['code' => 'SPP']);
})->throws(UniqueConstraintViolationException::class);

it('AC-23: stores money as an integer column', function () {
    $column = collect(Schema::getColumns('fee_rates'))->firstWhere('name', 'amount');

    expect($column)->not->toBeNull()
        ->and($column['type_name'])->toBeIn(['integer', 'bigint', 'int8', 'int']);
});

it('AC-23: restricts deleting records referenced by overrides', function (Closure $referenced) {
    $record = $referenced(FeeOverride::factory()->forScholarship(Scholarship::factory()->create())->create());

    expect(fn () => $record->delete())->toThrow(QueryException::class)
        ->and($record::query()->whereKey($record->getKey())->exists())->toBeTrue()
        ->and(FeeOverride::query()->count())->toBe(1);
})->with([
    'fee type' => [fn (FeeOverride $override): FeeType => $override->feeType],
    'scholarship' => [fn (FeeOverride $override): Scholarship => $override->scholarship],
    'approver' => [fn (FeeOverride $override): User => $override->approver],
]);

it('AC-23: keeps overrides when a student is soft deleted', function () {
    $override = FeeOverride::factory()->create();

    $override->student->delete();

    expect(FeeOverride::query()->whereKey($override->id)->exists())->toBeTrue();
    expect(fn () => $override->student()->withTrashed()->firstOrFail()->forceDelete())->toThrow(QueryException::class);
    expect(FeeOverride::query()->count())->toBe(1);
});

it('AC-23: rejects a duplicate scholarship name', function () {
    Scholarship::factory()->create(['name' => 'Yatim']);

    Scholarship::factory()->create(['name' => 'Yatim']);
})->throws(UniqueConstraintViolationException::class);

it('AC-23: stores applicant overrides without a foreign key', function () {
    $override = FeeOverride::factory()->create(['student_id' => null, 'applicant_id' => 999]);

    expect($override->fresh())->student_id->toBeNull()->applicant_id->toBe(999);
});

it('AC-24: stores override values as integer columns', function (string $table, string $column) {
    $found = collect(Schema::getColumns($table))->firstWhere('name', $column);

    expect($found)->not->toBeNull()
        ->and($found['type_name'])->toBeIn(['integer', 'bigint', 'int8', 'int']);
})->with([
    'override value' => ['fee_overrides', 'value'],
    'scholarship default value' => ['scholarships', 'default_value'],
]);

it('AC-24: indexes overrides by student, fee type, and start date', function () {
    $columns = collect(Schema::getIndexes('fee_overrides'))->pluck('columns');

    expect($columns->contains(['student_id', 'fee_type_id', 'valid_from']))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| F006: tagihan (invoices dan invoice_items)
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $attributes
 */
function constraintInvoice(Student $student, array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'student_id' => $student->id,
        'period' => CarbonImmutable::parse('2026-10-01')->startOfMonth(),
        'billing_group' => 'BULANAN',
        ...$attributes,
    ]);
}

it('AC-1: rejects a second active invoice for the same student, period and group', function () {
    $student = Student::factory()->create();
    constraintInvoice($student);

    expect(fn () => constraintInvoice($student))->toThrow(UniqueConstraintViolationException::class)
        ->and(Invoice::query()->count())->toBe(1);
});

it('AC-1: allows another group or another period for the same student', function () {
    $student = Student::factory()->create();
    constraintInvoice($student);
    constraintInvoice($student, ['billing_group' => 'ASRAMA']);
    constraintInvoice($student, ['period' => CarbonImmutable::parse('2026-11-01')->startOfMonth()]);

    expect(Invoice::query()->count())->toBe(3);
});

it('AC-1: rejects reactivating a cancelled invoice while an active one exists', function () {
    $student = Student::factory()->create();
    $cancelled = constraintInvoice($student, ['status' => 'batal']);
    constraintInvoice($student);

    $cancelled->status = 'belum';

    expect(fn () => $cancelled->save())->toThrow(UniqueConstraintViolationException::class);
});

it('AC-2: rejects two active applicant invoices with the same group', function () {
    Invoice::factory()->create(['student_id' => null, 'applicant_id' => 9, 'billing_group' => 'PSB']);

    expect(fn () => Invoice::factory()->create(['student_id' => null, 'applicant_id' => 9, 'billing_group' => 'PSB']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(Invoice::query()->count())->toBe(1);
});

it('AC-2: does not clash student invoices with a null applicant', function () {
    $student = Student::factory()->create();
    constraintInvoice($student);
    constraintInvoice($student, ['period' => CarbonImmutable::parse('2026-11-01')->startOfMonth()]);

    expect(Invoice::query()->whereNull('applicant_id')->count())->toBe(2);
});

it('AC-3: refuses an invoice without a student or an applicant', function () {
    expect(fn () => Invoice::factory()->create(['student_id' => null, 'applicant_id' => null]))
        ->toThrow(LogicException::class)
        ->and(Invoice::query()->count())->toBe(0);
});

it('AC-4: rejects a duplicate fee type on one invoice', function () {
    $invoice = Invoice::factory()->create();
    $feeType = FeeType::factory()->create();
    InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $feeType->id]);

    expect(fn () => InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $feeType->id]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(InvoiceItem::query()->count())->toBe(1);
});

it('AC-5: restricts deleting finance rows referenced by invoices', function (Closure $referenced) {
    $record = $referenced();

    expect(fn () => $record->delete())->toThrow(QueryException::class)
        ->and($record::query()->withoutGlobalScopes()->whereKey($record->getKey())->exists())->toBeTrue();
})->with([
    'invoice with items' => [function (): Invoice {
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        return $invoice;
    }],
    'fee type of an item' => [fn (): FeeType => InvoiceItem::factory()->create()->feeType],
    'fee override of an item' => [function (): FeeOverride {
        $override = FeeOverride::factory()->create();
        InvoiceItem::factory()->create(['fee_override_id' => $override->id]);

        return $override;
    }],
    'academic year of an invoice' => [fn (): AcademicYear => Invoice::factory()->create()->academicYear],
    'user who generated an invoice' => [function (): User {
        $user = User::factory()->create();
        Invoice::factory()->create(['generated_by' => $user->id]);

        return $user;
    }],
]);

it('AC-5: restricts force deleting a student with invoices', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => $invoice->student()->withTrashed()->first()->forceDelete())->toThrow(QueryException::class)
        ->and(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('AC-5: keeps invoices when a student is soft deleted', function () {
    $invoice = Invoice::factory()->create();

    $invoice->student->delete();

    expect(Invoice::withoutUnitScope()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('AC-6: stores invoice money as integers', function (string $table, string $column) {
    $found = collect(Schema::getColumns($table))->firstWhere('name', $column);

    expect($found)->not->toBeNull()
        ->and($found['type_name'])->toBeIn(['integer', 'bigint', 'int8', 'int']);
})->with([
    ['invoices', 'total'],
    ['invoices', 'paid_total'],
    ['invoice_items', 'base_amount'],
    ['invoice_items', 'discount_amount'],
    ['invoice_items', 'amount'],
    ['invoice_items', 'paid_amount'],
]);

it('AC-6: defaults paid totals to zero and status to belum', function () {
    $student = Student::factory()->create();
    $year = AcademicYear::factory()->create();
    $id = DB::table('invoices')->insertGetId([
        'invoice_no' => 'INV-202610-00001',
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
        'period' => '2026-10-01',
        'billing_group' => 'BULANAN',
        'due_on' => '2026-10-10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $itemId = DB::table('invoice_items')->insertGetId([
        'invoice_id' => $id,
        'fee_type_id' => FeeType::factory()->create()->id,
        'description' => 'SPP Oktober 2026',
        'base_amount' => 500000,
        'discount_amount' => 0,
        'amount' => 500000,
        'rate_source' => 'jenjang',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = DB::table('invoices')->find($id);

    expect((int) $invoice->total)->toBe(0)
        ->and((int) $invoice->paid_total)->toBe(0)
        ->and($invoice->status)->toBe('belum')
        ->and((int) DB::table('invoice_items')->find($itemId)->paid_amount)->toBe(0);
});

it('AC-6: indexes invoices by status and period', function () {
    expect(collect(Schema::getIndexes('invoices'))->pluck('columns')->contains(['status', 'period']))->toBeTrue();
});

it('AC-56: allows one active invoice beside cancelled ones', function () {
    $student = Student::factory()->create();
    constraintInvoice($student, ['status' => 'batal']);
    constraintInvoice($student);

    expect(Invoice::query()->count())->toBe(2);
});

it('AC-56: allows one active applicant invoice beside a cancelled one', function () {
    Invoice::factory()->create(['student_id' => null, 'applicant_id' => 9, 'billing_group' => 'PSB', 'status' => 'batal']);
    Invoice::factory()->create(['student_id' => null, 'applicant_id' => 9, 'billing_group' => 'PSB']);

    expect(fn () => Invoice::factory()->create(['student_id' => null, 'applicant_id' => 9, 'billing_group' => 'PSB', 'status' => 'sebagian']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('AC-57: allows many cancelled invoices with a null active marker', function () {
    $student = Student::factory()->create();

    foreach (range(1, 3) as $ignored) {
        constraintInvoice($student, ['status' => 'batal']);
    }

    $markers = DB::table('invoices')->pluck('active_marker');

    expect($markers)->toHaveCount(3)
        ->and($markers->filter(fn ($marker): bool => $marker !== null))->toBeEmpty();
});

it('AC-57: sets the active marker to 1 for every non-cancelled status', function (string $status) {
    $invoice = Invoice::factory()->create(['status' => $status]);

    expect((int) DB::table('invoices')->where('id', $invoice->id)->value('active_marker'))->toBe(1);
})->with(['belum', 'sebagian', 'lunas']);

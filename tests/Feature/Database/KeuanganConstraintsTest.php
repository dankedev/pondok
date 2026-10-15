<?php

use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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

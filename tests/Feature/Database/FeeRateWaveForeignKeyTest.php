<?php

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeRate;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('AC-2: keeps the generated keys after adding the wave foreign key', function (string $key) {
    $column = collect(Schema::getColumns('fee_rates'))->firstWhere('name', $key);

    expect($column)->not->toBeNull()
        ->and($column['generation'])->not->toBeNull();
})->with(['cohort_key', 'dormitory_key', 'wave_key']);

it('AC-2: keeps every fee rate column', function () {
    $columns = collect(Schema::getColumns('fee_rates'));

    expect($columns->pluck('name')->all())->toContain(
        'id', 'fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_id', 'dormitory_id', 'admission_wave_id',
        'amount', 'cohort_key', 'dormitory_key', 'wave_key', 'created_at', 'updated_at',
    )->and($columns->firstWhere('name', 'amount')['type_name'])->toBeIn(['integer', 'bigint', 'int8', 'int']);
});

it('AC-2: keeps the combination unique index and the fee type and unit index', function () {
    $indexes = collect(Schema::getIndexes('fee_rates'));
    $unique = $indexes->firstWhere('name', 'fee_rates_combination_unique');

    expect($unique)->not->toBeNull()
        ->and($unique['unique'])->toBeTrue()
        ->and($unique['columns'])->toBe(['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_key', 'dormitory_key', 'wave_key'])
        ->and($indexes->pluck('columns')->contains(['fee_type_id', 'unit_id']))->toBeTrue();
});

it('AC-2: keeps every original fee rate foreign key restrictive', function (string $column, string $table) {
    $foreignKey = collect(Schema::getForeignKeys('fee_rates'))->first(fn (array $key): bool => $key['columns'] === [$column]);

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey['foreign_table'])->toBe($table)
        ->and(strtolower($foreignKey['on_delete']))->toBe('restrict');
})->with([
    ['fee_type_id', 'fee_types'],
    ['effective_from_year_id', 'academic_years'],
    ['unit_id', 'units'],
    ['cohort_id', 'cohorts'],
    ['dormitory_id', 'dormitories'],
]);

it('AC-2: adds a restrictive foreign key from admission_wave_id to admission_waves', function () {
    $foreignKey = collect(Schema::getForeignKeys('fee_rates'))->first(fn (array $key): bool => $key['columns'] === ['admission_wave_id']);

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey['foreign_table'])->toBe('admission_waves')
        ->and($foreignKey['foreign_columns'])->toBe(['id'])
        ->and(strtolower($foreignKey['on_delete']))->toBe('restrict');
});

it('AC-3: enforces foreign keys on the sqlite test connection', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Hanya relevan untuk SQLite.');
    }

    expect((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
});

it('AC-3: fills wave_key from a real wave and generated keys from the dimensions', function () {
    $wave = AdmissionWave::factory()->create();
    $cohort = Cohort::factory()->create();
    $dormitory = Dormitory::factory()->create();

    $plain = FeeRate::factory()->create()->refresh();
    $full = FeeRate::factory()->create([
        'unit_id' => $cohort->unit_id,
        'cohort_id' => $cohort->id,
        'dormitory_id' => $dormitory->id,
        'admission_wave_id' => $wave->id,
    ])->refresh();

    expect((int) $plain->cohort_key)->toBe(0)->and((int) $plain->dormitory_key)->toBe(0)->and((int) $plain->wave_key)->toBe(0)
        ->and((int) $full->cohort_key)->toBe($cohort->id)->and((int) $full->dormitory_key)->toBe($dormitory->id)->and((int) $full->wave_key)->toBe($wave->id);
});

it('AC-3: still rejects duplicate rates with the same wave', function () {
    $wave = AdmissionWave::factory()->create();
    $rate = FeeRate::factory()->create(['admission_wave_id' => $wave->id]);
    $same = $rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id']);

    expect(fn () => FeeRate::factory()->create([...$same, 'admission_wave_id' => $wave->id]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-3: still rejects two default rates without a wave', function () {
    $rate = FeeRate::factory()->create();

    expect(fn () => FeeRate::factory()->create($rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id'])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-3: still rejects duplicate cohort and dormitory rates', function () {
    $cohort = Cohort::factory()->create();
    $dormitory = Dormitory::factory()->create();
    $rate = FeeRate::factory()->create(['unit_id' => $cohort->unit_id, 'cohort_id' => $cohort->id, 'dormitory_id' => $dormitory->id]);

    expect(fn () => FeeRate::factory()->create($rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_id', 'dormitory_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('AC-3: allows the same combination for different waves and without a wave', function () {
    $year = AcademicYear::factory()->create();
    $first = AdmissionWave::factory()->create(['academic_year_id' => $year->id, 'name' => 'Gelombang 1']);
    $second = AdmissionWave::factory()->create(['academic_year_id' => $year->id, 'name' => 'Gelombang 2']);
    $rate = FeeRate::factory()->create(['admission_wave_id' => $first->id]);
    $same = $rate->only(['fee_type_id', 'effective_from_year_id', 'unit_id']);

    FeeRate::factory()->create([...$same, 'admission_wave_id' => $second->id]);
    FeeRate::factory()->create([...$same, 'admission_wave_id' => null]);

    expect(FeeRate::query()->count())->toBe(3);
});

it('AC-3: rejects a rate for an unknown wave', function () {
    expect(fn () => FeeRate::factory()->create(['admission_wave_id' => 999999]))->toThrow(QueryException::class)
        ->and(FeeRate::query()->count())->toBe(0);
});

it('AC-3: restricts deleting a wave that has rates', function () {
    $wave = AdmissionWave::factory()->create();
    FeeRate::factory()->create(['admission_wave_id' => $wave->id]);

    expect(fn () => $wave->delete())->toThrow(QueryException::class)
        ->and(AdmissionWave::query()->whereKey($wave->id)->exists())->toBeTrue()
        ->and(FeeRate::query()->count())->toBe(1);
});

it('AC-3: deletes a wave without rates', function () {
    $wave = AdmissionWave::factory()->create();

    $wave->delete();

    expect(AdmissionWave::query()->whereKey($wave->id)->exists())->toBeFalse();
});

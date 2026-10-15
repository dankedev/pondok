<?php

use App\Models\AdmissionTrack;
use App\Models\FeeType;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasangan jalur dan jenis biaya langsung ke tabel pivot.
 *
 * @return array{AdmissionTrack, FeeType}
 */
function seleksiPivotPair(): array
{
    $track = AdmissionTrack::factory()->create();
    $type = FeeType::factory()->create();

    DB::table('admission_track_fee_type')->insert([
        'admission_track_id' => $track->id,
        'fee_type_id' => $type->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$track, $type];
}

it('AC-1: creates the track fee type pivot', function () {
    expect(Schema::hasTable('admission_track_fee_type'))->toBeTrue()
        ->and(Schema::hasColumns('admission_track_fee_type', ['id', 'admission_track_id', 'fee_type_id', 'created_at', 'updated_at']))->toBeTrue();

    $foreignKey = fn (string $column) => collect(Schema::getForeignKeys('admission_track_fee_type'))->first(fn (array $key): bool => $key['columns'] === [$column]);

    expect($foreignKey('admission_track_id'))->not->toBeNull()
        ->and($foreignKey('admission_track_id')['foreign_table'])->toBe('admission_tracks')
        ->and(strtolower($foreignKey('admission_track_id')['on_delete']))->toBe('restrict')
        ->and($foreignKey('fee_type_id'))->not->toBeNull()
        ->and($foreignKey('fee_type_id')['foreign_table'])->toBe('fee_types')
        ->and(strtolower($foreignKey('fee_type_id')['on_delete']))->toBe('restrict');

    $unique = collect(Schema::getIndexes('admission_track_fee_type'))->where('unique', true)->pluck('columns')->all();

    expect($unique)->toContain(['admission_track_id', 'fee_type_id']);
});

it('AC-1: rejects a duplicate track and fee type pair', function () {
    [$track, $type] = seleksiPivotPair();

    expect(fn () => DB::table('admission_track_fee_type')->insert([
        'admission_track_id' => $track->id,
        'fee_type_id' => $type->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-1: refuses to delete a mapped fee type', function () {
    [, $type] = seleksiPivotPair();

    expect(fn () => $type->delete())->toThrow(QueryException::class)
        ->and(FeeType::query()->whereKey($type->id)->exists())->toBeTrue();
});

it('AC-1: refuses to delete a mapped track', function () {
    [$track] = seleksiPivotPair();

    expect(fn () => $track->delete())->toThrow(QueryException::class)
        ->and(AdmissionTrack::query()->whereKey($track->id)->exists())->toBeTrue();
});

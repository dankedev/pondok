<?php

use App\Models\AcademicYear;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\DocumentType;
use App\Models\Scholarship;
use App\Models\Unit;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('AC-1: creates the psb master tables', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'document types' => ['document_types', ['code', 'name', 'owner', 'is_required', 'has_expiry', 'is_confidential', 'is_active']],
    'admission waves' => ['admission_waves', ['academic_year_id', 'name', 'opens_on', 'closes_on', 'quota', 'is_open']],
    'admission tracks' => ['admission_tracks', ['code', 'name', 'scholarship_id', 'terms', 'is_active']],
    'admission requirements' => ['admission_requirements', ['unit_id', 'document_type_id', 'name', 'is_required', 'sort_order', 'unit_key']],
]);

it('AC-4: rejects duplicate track codes', function () {
    AdmissionTrack::factory()->create(['code' => 'REGULER']);

    expect(fn () => AdmissionTrack::factory()->create(['code' => 'REGULER']))->toThrow(UniqueConstraintViolationException::class)
        ->and(AdmissionTrack::query()->count())->toBe(1);
});

it('AC-4: rejects duplicate document type codes', function () {
    DocumentType::factory()->create(['code' => 'KK']);

    expect(fn () => DocumentType::factory()->create(['code' => 'KK']))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-4: rejects duplicate wave names per academic year', function () {
    $year = AcademicYear::factory()->create();
    AdmissionWave::factory()->create(['academic_year_id' => $year->id, 'name' => 'Gelombang 1']);

    expect(fn () => AdmissionWave::factory()->create(['academic_year_id' => $year->id, 'name' => 'Gelombang 1']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(AdmissionWave::query()->count())->toBe(1);

    AdmissionWave::factory()->create(['academic_year_id' => AcademicYear::factory()->create()->id, 'name' => 'Gelombang 1']);

    expect(AdmissionWave::query()->count())->toBe(2);
});

it('AC-4: rejects duplicate requirements through unit_key', function (?string $unitCode) {
    $kk = DocumentType::factory()->create(['code' => 'KK']);
    $unit = $unitCode === null ? null : Unit::factory()->create(['code' => $unitCode]);
    $attributes = ['document_type_id' => $kk->id, 'unit_id' => $unit?->id];
    AdmissionRequirement::factory()->create([...$attributes, 'name' => 'Kartu Keluarga']);

    expect(fn () => AdmissionRequirement::factory()->create([...$attributes, 'name' => 'KK lagi']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(AdmissionRequirement::query()->count())->toBe(1);
})->with(['all units' => [null], 'one unit' => ['SD']]);

it('AC-4: allows the same document type for different units', function () {
    $kk = DocumentType::factory()->create(['code' => 'KK']);
    $sd = Unit::factory()->create();
    $sltp = Unit::factory()->create();

    AdmissionRequirement::factory()->create(['document_type_id' => $kk->id, 'unit_id' => $sd->id]);
    AdmissionRequirement::factory()->create(['document_type_id' => $kk->id, 'unit_id' => $sltp->id]);

    expect(AdmissionRequirement::query()->count())->toBe(2);
});

it('AC-4: fills unit_key with 0 for all units and the unit id otherwise', function () {
    $kk = DocumentType::factory()->create();
    $akta = DocumentType::factory()->create();
    $unit = Unit::factory()->create();

    $all = AdmissionRequirement::factory()->create(['document_type_id' => $kk->id, 'unit_id' => null])->refresh();
    $one = AdmissionRequirement::factory()->create(['document_type_id' => $akta->id, 'unit_id' => $unit->id])->refresh();

    expect((int) $all->unit_key)->toBe(0)->and((int) $one->unit_key)->toBe($unit->id);
});

it('AC-4: restricts deleting referenced psb master data', function (Closure $referenced) {
    $record = $referenced();

    expect(fn () => $record->delete())->toThrow(QueryException::class)
        ->and($record::query()->whereKey($record->getKey())->exists())->toBeTrue();
})->with([
    'academic year with a wave' => [fn (): AcademicYear => AdmissionWave::factory()->create()->academicYear],
    'scholarship linked to a track' => [function (): Scholarship {
        $scholarship = Scholarship::factory()->create();
        AdmissionTrack::factory()->create(['scholarship_id' => $scholarship->id]);

        return $scholarship;
    }],
    'unit with a requirement' => [function (): Unit {
        $unit = Unit::factory()->create();
        AdmissionRequirement::factory()->create(['unit_id' => $unit->id, 'document_type_id' => DocumentType::factory()->create()->id]);

        return $unit;
    }],
    'document type used by a requirement' => [function (): DocumentType {
        $type = DocumentType::factory()->create();
        AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $type->id]);

        return $type;
    }],
]);

it('AC-4: keeps psb master rows without soft deletes', function () {
    foreach ([DocumentType::class, AdmissionWave::class, AdmissionTrack::class, AdmissionRequirement::class] as $model) {
        expect(class_uses_recursive($model))->not->toContain(SoftDeletes::class);
    }
});

<?php

use App\Models\ApplicantDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

/**
 * Migration F012 yang dicari lewat glob (nama berakhiran `$suffix`); gagal bila belum ada tepat satu.
 */
function daftarUlangMigration(string $suffix): object
{
    $found = glob(database_path("migrations/*_{$suffix}.php"));

    expect($found)->toHaveCount(1, "Migration *_{$suffix}.php belum ada");

    $migrator = app('migrator');

    return (fn () => $this->resolvePath($found[0]))->call($migrator);
}

/**
 * Struktur `documents` yang dibandingkan sebelum dan sesudah migration: kolom, index (nama, kolom, unique), FK selain
 * FK `source_applicant_document_id`.
 *
 * @return array<string, mixed>
 */
function daftarUlangDocumentsStructure(): array
{
    return [
        'columns' => collect(Schema::getColumns('documents'))->map(fn (array $column): array => [$column['name'], $column['type_name'], $column['nullable']])->all(),
        'indexes' => collect(Schema::getIndexes('documents'))->map(fn (array $index): array => [$index['name'], $index['columns'], $index['unique']])->sortBy(0)->values()->all(),
        'foreign_keys' => collect(Schema::getForeignKeys('documents'))
            ->reject(fn (array $key): bool => $key['columns'] === ['source_applicant_document_id'])
            ->map(fn (array $key): array => [$key['columns'], $key['foreign_table'], strtolower($key['on_delete'])])->sortBy(fn (array $row): string => $row[0][0])->values()->all(),
    ];
}

function daftarUlangAssertDocumentsIntact(): void
{
    $indexes = collect(Schema::getIndexes('documents'));
    $keys = collect(Schema::getForeignKeys('documents'));

    foreach (['document_type_id' => 'document_types', 'verified_by' => 'users', 'uploaded_by' => 'users', 'replaced_by_id' => 'documents'] as $column => $table) {
        $key = $keys->first(fn (array $key): bool => $key['columns'] === [$column]);

        expect($key)->not->toBeNull("FK {$column}")->and($key['foreign_table'])->toBe($table)->and(strtolower($key['on_delete']))->toBe('restrict');
    }

    expect($indexes->firstWhere('name', 'documents_owner_type_index')['columns'])->toBe(['documentable_type', 'documentable_id', 'document_type_id'])
        ->and($indexes->pluck('columns')->all())->toContain(['expires_on'])->toContain(['status'])->toContain(['source_applicant_document_id'])
        ->and($indexes->where('unique', true)->pluck('columns')->all())->toContain(['replaced_by_id'])
        ->and(collect(Schema::getColumns('documents'))->firstWhere('name', 'number')['type_name'])->toBe('text');
}

it('AC-1: links documents to their admission source', function () {
    $key = collect(Schema::getForeignKeys('documents'))->first(fn (array $key): bool => $key['columns'] === ['source_applicant_document_id']);

    expect($key)->not->toBeNull('FK source_applicant_document_id belum ada')
        ->and($key['foreign_table'])->toBe('applicant_documents')
        ->and($key['foreign_columns'])->toBe(['id'])
        ->and(strtolower($key['on_delete']))->toBeIn(['restrict', 'no action']);

    $source = ApplicantDocument::factory()->create();
    $document = dokumenCreate(Student::factory()->create(), DocumentType::factory()->create(), ['source_applicant_document_id' => $source->id]);

    expect(fn () => DB::table('applicant_documents')->where('id', $source->id)->delete())->toThrow(QueryException::class)
        ->and(ApplicantDocument::query()->whereKey($source->id)->exists())->toBeTrue()
        ->and($document->fresh()->source_applicant_document_id)->toBe($source->id);
});

it('AC-1: rejects a source that is not an applicant document', function () {
    expect(fn () => dokumenCreate(Student::factory()->create(), DocumentType::factory()->create(), ['source_applicant_document_id' => 987654]))
        ->toThrow(QueryException::class);
});

it('AC-2: keeps documents indexes and foreign keys after the rebuild', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Hanya relevan untuk SQLite.');
    }

    $migration = daftarUlangMigration('add_source_applicant_document_foreign_key_to_documents_table');

    $migration->down();
    expect(collect(Schema::getForeignKeys('documents'))->contains(fn (array $key): bool => $key['columns'] === ['source_applicant_document_id']))->toBeFalse();
    $before = daftarUlangDocumentsStructure();

    $migration->up();

    expect(daftarUlangDocumentsStructure())->toBe($before)
        ->and(collect(Schema::getForeignKeys('documents'))->contains(fn (array $key): bool => $key['columns'] === ['source_applicant_document_id']))->toBeTrue();

    daftarUlangAssertDocumentsIntact();
});

it('AC-3: survives rollback and migrate with data on sqlite', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Hanya relevan untuk SQLite.');
    }

    $migration = daftarUlangMigration('add_source_applicant_document_foreign_key_to_documents_table');
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();
    $old = dokumenCreate($student, $type);
    $new = dokumenCreate($student, $type);
    $old->forceFill(['replaced_by_id' => $new->id])->save();
    $source = ApplicantDocument::factory()->create();
    dokumenCreate($student, DocumentType::factory()->create(), ['source_applicant_document_id' => $source->id]);

    $rows = fn (): array => DB::table('documents')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    $before = $rows();

    $migration->down();
    expect($rows())->toBe($before);

    $migration->up();

    expect($rows())->toBe($before)
        ->and(Document::withoutUnitScope()->count())->toBe(3)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    daftarUlangAssertDocumentsIntact();
    expect(collect(Schema::getForeignKeys('documents'))->contains(fn (array $key): bool => $key['columns'] === ['source_applicant_document_id']))->toBeTrue();
});

<?php

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Student;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

it('AC-1: creates the documents table from the ERD', function () {
    expect(Schema::hasColumns('documents', [
        'id', 'document_type_id', 'documentable_type', 'documentable_id', 'number', 'issued_on', 'expires_on', 'file_path',
        'original_name', 'mime_type', 'size_kb', 'status', 'verified_by', 'verified_at', 'uploaded_by', 'replaced_by_id',
        'source_applicant_document_id', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('documents', 'deleted_at'))->toBeFalse()
        ->and(class_uses_recursive(Document::class))->not->toContain(SoftDeletes::class);
});

it('AC-1: indexes the owner, expiry and replacement columns', function () {
    $indexes = collect(Schema::getIndexes('documents'));

    expect($indexes->pluck('columns')->all())
        ->toContain(['documentable_type', 'documentable_id', 'document_type_id'])
        ->toContain(['expires_on'])
        ->and($indexes->where('unique', true)->pluck('columns')->all())->toContain(['replaced_by_id'])
        ->and($indexes->firstWhere('columns', ['documentable_type', 'documentable_id', 'document_type_id'])['unique'])->toBeFalse();
});

it('AC-1: restricts the foreign keys and leaves polymorphic and PSB source columns free', function () {
    $keys = collect(Schema::getForeignKeys('documents'));

    foreach (['document_type_id' => 'document_types', 'verified_by' => 'users', 'uploaded_by' => 'users', 'replaced_by_id' => 'documents'] as $column => $table) {
        $key = $keys->first(fn (array $key): bool => $key['columns'] === [$column]);

        expect($key)->not->toBeNull("FK {$column}")
            ->and($key['foreign_table'])->toBe($table)
            ->and(strtolower($key['on_delete']))->toBe('restrict');
    }

    expect($keys->contains(fn (array $key): bool => in_array('source_applicant_document_id', $key['columns'], true)))->toBeFalse()
        ->and($keys->contains(fn (array $key): bool => in_array('documentable_id', $key['columns'], true)))->toBeFalse();
});

it('AC-2: restricts deleting referenced rows', function () {
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();
    $old = dokumenCreate($student, $type);
    $new = dokumenCreate($student, $type);
    $old->forceFill(['replaced_by_id' => $new->id])->save();

    expect(fn () => DB::table('document_types')->where('id', $type->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('documents')->where('id', $new->id)->delete())->toThrow(QueryException::class)
        ->and(Document::query()->count())->toBe(2)
        ->and(DocumentType::query()->count())->toBe(1);
});

it('AC-2: allows one replacement per document only', function () {
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();
    $first = dokumenCreate($student, $type);
    $second = dokumenCreate($student, $type);
    $newest = dokumenCreate($student, $type);
    $first->forceFill(['replaced_by_id' => $newest->id])->save();

    expect(fn () => $second->forceFill(['replaced_by_id' => $newest->id])->save())->toThrow(UniqueConstraintViolationException::class)
        ->and($second->fresh()->replaced_by_id)->toBeNull();
});

it('AC-2: stores several documents without a replacement and defaults the status to menunggu', function () {
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();

    dokumenCreate($student, $type);
    dokumenCreate($student, $type);

    $documents = Document::query()->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->every(fn (Document $document): bool => $document->replaced_by_id === null && $document->status === 'menunggu'))->toBeTrue();

    $raw = DB::table('documents')->insertGetId([
        'document_type_id' => $type->id, 'documentable_type' => 'student', 'documentable_id' => $student->id,
        'file_path' => 'documents/student/1/x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_kb' => 1,
        'uploaded_by' => $documents->first()->uploaded_by, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('documents')->where('id', $raw)->value('status'))->toBe('menunggu');
});

it('AC-3: encrypts document numbers at rest', function () {
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();
    $document = dokumenCreate($student, $type, ['number' => '3201234567890001']);

    $raw = DB::table('documents')->where('id', $document->id)->value('number');

    expect($raw)->not->toBe('3201234567890001')
        ->and($raw)->not->toContain('3201234567890001')
        ->and($document->fresh()->number)->toBe('3201234567890001');
});

it('AC-3: stores a 50 character number in full and keeps NULL as NULL', function () {
    $student = Student::factory()->create();
    $type = DocumentType::factory()->create();
    $long = str_repeat('AB12', 12).'CD';
    $withNumber = dokumenCreate($student, $type, ['number' => $long]);
    $without = dokumenCreate($student, $type, ['number' => null]);

    expect(strlen($long))->toBe(50)
        ->and($withNumber->fresh()->number)->toBe($long)
        ->and(DB::table('documents')->where('id', $without->id)->value('number'))->toBeNull()
        ->and($without->fresh()->number)->toBeNull();
});

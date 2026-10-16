<?php

use App\Actions\Dokumen\CheckDocumentCompleteness;
use App\Models\ApplicantDocument;
use App\Models\Cohort;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

function daftarUlangCopies(?int $sourceId = null): Collection
{
    return Document::withoutUnitScope()->whereNotNull('source_applicant_document_id')
        ->when($sourceId, fn ($query, int $id) => $query->where('source_applicant_document_id', $id))
        ->orderBy('id')->get();
}

/**
 * Kakak ber-wali "Budi Santoso" (nomor sama) agar wali dipakai ulang oleh konversi.
 */
function daftarUlangReusableGuardian(object $f): Guardian
{
    $cohort = Cohort::query()->firstOrCreate(['unit_id' => $f->sltp->id, 'entry_year' => 2024], ['name' => 'Angkatan 2024']);
    $guardian = Guardian::factory()->create(['name' => 'Budi Santoso', 'phone' => '+6281234567890']);
    Student::factory()->forCohort($cohort)->create()->guardians()->attach($guardian->id, ['relation' => 'ayah', 'is_billing_contact' => true]);

    return $guardian;
}

it('AC-22: copies valid documents to the student', function () {
    $applicant = daftarUlangApplicant($this->f);
    $sources = ApplicantDocument::withoutGlobalScopes()->where('applicant_id', $applicant->id)->orderBy('id')->get();
    $sourceRows = $sources->map->only(['id', 'status', 'file_path', 'original_name', 'verified_by'])->all();

    $result = daftarUlangRun($applicant);
    $student = $result['student'];
    $copies = daftarUlangCopies();

    expect($sources)->toHaveCount(2)
        ->and($result['documents_copied'])->toBe(2)
        ->and($result['documents_skipped'])->toBe([])
        ->and($copies)->toHaveCount(2);

    foreach ($sources as $source) {
        $copy = $copies->firstWhere('source_applicant_document_id', $source->id);
        $type = $source->requirement->documentType;

        expect($copy)->not->toBeNull()
            ->and($copy->document_type_id)->toBe($type->id)
            ->and($copy->documentable_type)->toBe('student')
            ->and($copy->documentable_id)->toBe($student->id)
            ->and($copy->status)->toBe('valid')
            ->and($copy->verified_by)->toBe($source->verified_by)
            ->and($copy->verified_at->toDateTimeString())->toBe($source->verified_at->toDateTimeString())
            ->and($copy->uploaded_by)->toBe($this->f->user->id)
            ->and($copy->original_name)->toBe($source->original_name)
            ->and($copy->mime_type)->toBe($source->mime_type)
            ->and($copy->size_kb)->toBe($source->size_kb)
            ->and($copy->number)->toBeNull()->and($copy->issued_on)->toBeNull()->and($copy->expires_on)->toBeNull()->and($copy->replaced_by_id)->toBeNull()
            ->and($copy->file_path)->toStartWith("documents/student/{$student->id}/")->not->toBe($source->file_path)
            ->and(Storage::disk('private')->exists($copy->file_path))->toBeTrue()
            ->and(Storage::disk('private')->get($copy->file_path))->toBe(Storage::disk('private')->get($source->file_path));
    }

    expect(ApplicantDocument::withoutGlobalScopes()->where('applicant_id', $applicant->id)->orderBy('id')->get()->map->only(['id', 'status', 'file_path', 'original_name', 'verified_by'])->all())->toBe($sourceRows)
        ->and(collect($sourceRows)->every(fn (array $row): bool => Storage::disk('private')->exists($row['file_path'])))->toBeTrue()
        ->and(app(CheckDocumentCompleteness::class)->handle($student))->toBe([]);
});

it('AC-22: skips documents that are not valid', function () {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('PROPOSAL', 'santri');
    daftarUlangValidDocument($applicant, 'PROPOSAL', 'ditolak');
    $filesBefore = count(Storage::disk('private')->allFiles());

    $result = daftarUlangRun($applicant);

    expect($result['documents_copied'])->toBe(2)
        ->and(daftarUlangCopies())->toHaveCount(2)
        ->and(Storage::disk('private')->allFiles())->toHaveCount($filesBefore + 2);
});

it('AC-23: routes guardian documents and skips unsupported ones', function () {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('KTP_WALI', 'wali');
    daftarUlangRequirement('SK_STAFF', 'staff');
    daftarUlangRequirement('FOTO_X', 'santri');
    $guardianDocument = daftarUlangValidDocument($applicant, 'KTP_WALI');
    $staffDocument = daftarUlangValidDocument($applicant, 'SK_STAFF');
    $missingDocument = daftarUlangValidDocument($applicant, 'FOTO_X', 'valid', withFile: false);
    Log::spy();

    $result = daftarUlangRun($applicant);
    $copy = daftarUlangCopies($guardianDocument->id)->sole();

    expect($result['documents_copied'])->toBe(3)
        ->and($copy->documentable_type)->toBe('guardian')
        ->and($copy->documentable_id)->toBe($result['guardian']->id)
        ->and($copy->file_path)->toStartWith("documents/guardian/{$result['guardian']->id}/")
        ->and(Storage::disk('private')->exists($copy->file_path))->toBeTrue()
        ->and(daftarUlangCopies($staffDocument->id))->toHaveCount(0)
        ->and(daftarUlangCopies($missingDocument->id))->toHaveCount(0)
        ->and($result['documents_skipped'])->toEqualCanonicalizing([
            ['applicant_document_id' => $staffDocument->id, 'reason' => 'owner_not_supported'],
            ['applicant_document_id' => $missingDocument->id, 'reason' => 'file_missing'],
        ])
        ->and(Student::withoutGlobalScopes()->count())->toBe(1);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('AC-23: fails the whole conversion when copying a file returns false', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();
    $disk = Mockery::mock(Storage::disk('private'));
    $disk->shouldReceive('copy')->andReturn(false);
    Storage::set('private', $disk);

    expect(fn () => daftarUlangRun($applicant))->toThrow(RuntimeException::class, __('pondok.psb.errors.file_copy_failed'));

    expect(daftarUlangSnapshot())->toBe($before)->and(Student::withoutGlobalScopes()->count())->toBe(0);
});

it('AC-23: removes the first copy when the second copy fails', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();
    $real = Storage::disk('private');
    $calls = 0;
    $disk = Mockery::mock($real);
    $disk->shouldReceive('copy')->andReturnUsing(function (string $from, string $to) use ($real, &$calls): bool {
        return ++$calls === 1 ? $real->copy($from, $to) : false;
    });
    Storage::set('private', $disk);

    expect(fn () => daftarUlangRun($applicant))->toThrow(RuntimeException::class, __('pondok.psb.errors.file_copy_failed'));

    expect($calls)->toBe(2)->and(daftarUlangSnapshot())->toBe($before);
});

it('AC-24: logs copies without personal data', function () {
    $applicant = daftarUlangApplicant($this->f);
    $by = $this->f->user;

    $result = daftarUlangRun($applicant, by: $by);
    $copies = daftarUlangCopies();
    $activities = Activity::query()->where('log_name', 'dokumen')->where('event', 'copied_from_applicant')->orderBy('id')->get();

    expect($activities)->toHaveCount(2);

    foreach ($copies as $copy) {
        $activity = $activities->firstWhere('subject_id', $copy->id);
        $type = DocumentType::query()->findOrFail($copy->document_type_id);

        expect($activity)->not->toBeNull()
            ->and($activity->subject_type)->toBe($copy->getMorphClass())
            ->and($activity->causer_id)->toBe($by->id)
            ->and($activity->properties->all())->toMatchArray([
                'document_type' => $type->code, 'documentable_type' => 'student', 'documentable_id' => $result['student']->id,
                'source_applicant_document_id' => $copy->source_applicant_document_id, 'status' => 'valid',
            ])
            ->and($activity->properties->keys()->all())->not->toContain('number')->not->toContain('file_path')->not->toContain('original_name')
            ->and(json_encode($activity->properties))->not->toContain('asli.pdf')->not->toContain('documents/student');
    }
});

it('AC-38: keeps the current guardian document', function () {
    $guardian = daftarUlangReusableGuardian($this->f);
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('KTP_WALI', 'wali');
    $existing = dokumenValid($guardian, 'KTP_WALI');
    $existingRow = $existing->fresh()->only(['id', 'status', 'replaced_by_id', 'file_path', 'verified_by']);
    $source = daftarUlangValidDocument($applicant, 'KTP_WALI');
    $filesBefore = count(Storage::disk('private')->allFiles());

    $result = daftarUlangRun($applicant);

    expect($result['guardian_reused'])->toBeTrue()
        ->and($result['guardian']->id)->toBe($guardian->id)
        ->and($result['documents_skipped'])->toEqualCanonicalizing([['applicant_document_id' => $source->id, 'reason' => 'owner_has_current']])
        ->and($result['documents_copied'])->toBe(2)
        ->and($existing->fresh()->only(['id', 'status', 'replaced_by_id', 'file_path', 'verified_by']))->toBe($existingRow)
        ->and(Document::withoutUnitScope()->where('documentable_type', 'guardian')->where('documentable_id', $guardian->id)->whereNull('replaced_by_id')->count())->toBe(1)
        ->and(daftarUlangCopies($source->id))->toHaveCount(0)
        ->and(Storage::disk('private')->allFiles())->toHaveCount($filesBefore + 2);
});

it('AC-38: copies the guardian document when the old one was replaced and nothing is current', function () {
    $guardian = daftarUlangReusableGuardian($this->f);
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('KTP_WALI', 'wali');
    $other = DocumentType::factory()->create(['code' => 'LAIN_WALI', 'owner' => 'wali']);
    $replacement = dokumenValid($guardian, $other);
    $old = dokumenValid($guardian, 'KTP_WALI');
    $old->forceFill(['replaced_by_id' => $replacement->id])->save();
    $source = daftarUlangValidDocument($applicant, 'KTP_WALI');

    $result = daftarUlangRun($applicant);
    $copy = daftarUlangCopies($source->id)->sole();

    expect($result['documents_skipped'])->toBe([])
        ->and($copy->documentable_type)->toBe('guardian')
        ->and($copy->documentable_id)->toBe($guardian->id)
        ->and($copy->replaced_by_id)->toBeNull()
        ->and($old->fresh()->replaced_by_id)->toBe($replacement->id);
});

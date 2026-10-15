<?php

use App\Actions\Dokumen\ReplaceDocument;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->d1 = dokumenValid($this->f->s1, 'KK', ['number' => '3201234567890001', 'content' => 'isi-kk-lama']);
});

it('AC-18: adds a new version without overwriting', function () {
    $before = ['file_path' => $this->d1->file_path, 'verified_by' => $this->d1->verified_by, 'verified_at' => $this->d1->verified_at->toDateTimeString()];
    $filesBefore = dokumenPrivateFiles();

    $d2 = app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf('kk-baru.pdf', 200), 'number' => '3201234567899999'], $this->f->admin);

    $d1 = $this->d1->fresh();

    expect(Document::query()->count())->toBe(2)
        ->and($d2->status)->toBe('menunggu')
        ->and($d2->document_type_id)->toBe($d1->document_type_id)
        ->and($d2->documentable_type)->toBe('student')
        ->and($d2->documentable_id)->toBe($this->f->s1->id)
        ->and($d2->uploaded_by)->toBe($this->f->admin->id)
        ->and($d2->number)->toBe('3201234567899999')
        ->and($d2->original_name)->toBe('kk-baru.pdf')
        ->and($d2->verified_by)->toBeNull()
        ->and($d2->replaced_by_id)->toBeNull()
        ->and($d1->replaced_by_id)->toBe($d2->id)
        ->and($d1->status)->toBe('valid')
        ->and($d1->number)->toBe('3201234567890001')
        ->and($d1->file_path)->toBe($before['file_path'])
        ->and($d1->verified_by)->toBe($before['verified_by'])
        ->and($d1->verified_at->toDateTimeString())->toBe($before['verified_at'])
        ->and(Storage::disk('private')->get($d1->file_path))->toBe('isi-kk-lama')
        ->and(Storage::disk('private')->exists($d2->file_path))->toBeTrue()
        ->and(dokumenPrivateFiles())->toHaveCount(count($filesBefore) + 1)
        ->and($this->f->s1->documents()->current()->where('document_type_id', $d1->document_type_id)->pluck('id')->all())->toBe([$d2->id])
        ->and($d1->display_status)->toBe('diganti');
});

it('AC-19: replaces only the current version', function () {
    $d2 = app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $this->f->admin);
    $count = Document::query()->count();
    $files = dokumenPrivateFiles();

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($this->d1->fresh(), ['file' => dokumenPdf()], $this->f->admin), 'already_replaced', 'document');

    expect(Document::query()->count())->toBe($count)
        ->and(dokumenPrivateFiles())->toBe($files);

    $d3 = app(ReplaceDocument::class)->handle($d2, ['file' => dokumenPdf()], $this->f->admin);

    expect($d2->fresh()->replaced_by_id)->toBe($d3->id)
        ->and($this->d1->fresh()->replaced_by_id)->toBe($d2->id)
        ->and($d3->replaced_by_id)->toBeNull();
});

it('AC-19: refuses a stale copy of an already replaced document', function () {
    $stale = Document::query()->find($this->d1->id);
    app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $this->f->admin);

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($stale, ['file' => dokumenPdf()], $this->f->admin), 'already_replaced', 'document');

    expect(Document::query()->count())->toBe(2);
});

it('AC-20: allows a new version of a document whose type is inactive', function () {
    $this->f->types['KK']->update(['is_active' => false]);

    $d2 = app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $this->f->admin);

    expect($d2->document_type_id)->toBe($this->f->types['KK']->id)
        ->and($this->d1->fresh()->replaced_by_id)->toBe($d2->id);
});

it('AC-20: refuses archived students', function () {
    $this->f->s1->delete();
    $files = dokumenPrivateFiles();

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $this->f->admin), 'owner_archived', 'documentable');

    expect(Document::query()->count())->toBe(1)
        ->and($this->d1->fresh()->replaced_by_id)->toBeNull()
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('AC-20: refuses students outside the unit scope of the user', function () {
    $scoped = keuanganUser('admin-sekolah', ['SLTP']);
    $this->actingAs($scoped);
    $files = dokumenPrivateFiles();

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $scoped), 'owner_not_found', 'documentable');

    expect(Document::withoutUnitScope()->count())->toBe(1)
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('AC-20: validates replacements', function (string $case, string $reason, string $field) {
    $files = dokumenPrivateFiles();
    $data = ['file' => dokumenPdf()];

    switch ($case) {
        case 'text file':
            $data['file'] = UploadedFile::fake()->create('catatan.txt', 20);
            break;
        case 'no file':
            $data = [];
            break;
        case 'number too long':
            $data['number'] = str_repeat('1', 51);
            break;
        case 'issue date tomorrow':
            $data['issued_on'] = '2026-10-16';
            break;
        case 'expiry on a type without expiry':
            $data['expires_on'] = '2030-01-01';
            break;
        case 'pdf over 2 MB':
            $data['file'] = dokumenPdf('besar.pdf', 2049);
            break;
    }

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($this->d1, $data, $this->f->admin), $reason, $field);

    expect(Document::query()->count())->toBe(1)
        ->and($this->d1->fresh()->replaced_by_id)->toBeNull()
        ->and(dokumenPrivateFiles())->toBe($files);
})->with([
    'text file' => ['text file', 'file_invalid', 'file'],
    'no file' => ['no file', 'file_required', 'file'],
    'pdf over 2 MB' => ['pdf over 2 MB', 'file_invalid', 'file'],
    'number too long' => ['number too long', 'number_too_long', 'number'],
    'issue date tomorrow' => ['issue date tomorrow', 'issued_on_future', 'issued_on'],
    'expiry on a type without expiry' => ['expiry on a type without expiry', 'expiry_not_applicable', 'expires_on'],
]);

it('AC-20: keeps the old version when saving the replacement fails', function () {
    Event::listen('eloquent.creating: '.Document::class, fn () => throw new RuntimeException('boom'));
    $files = dokumenPrivateFiles();

    try {
        expect(fn () => app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf()], $this->f->admin))->toThrow(RuntimeException::class, 'boom');
    } finally {
        Event::forget('eloquent.creating: '.Document::class);
    }

    expect($this->d1->fresh()->replaced_by_id)->toBeNull()
        ->and(Document::query()->count())->toBe(1)
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('AC-21: logs replacements without numbers', function () {
    $d2 = app(ReplaceDocument::class)->handle($this->d1, ['file' => dokumenPdf('kk-baru.pdf'), 'number' => '3201234567899999'], $this->f->admin);

    $activity = dokumenActivities()->sole();
    $json = $activity->toJson();

    expect($activity->description)->toBe('replaced')
        ->and($activity->subject->is($d2))->toBeTrue()
        ->and($activity->causer->is($this->f->admin))->toBeTrue()
        ->and($activity->properties['replaces_id'])->toBe($this->d1->id)
        ->and($activity->properties['document_type'])->toBe('KK')
        ->and($activity->properties['documentable_type'])->toBe('student')
        ->and($activity->properties['documentable_id'])->toBe($this->f->s1->id)
        ->and($json)->not->toContain('3201234567890001')
        ->not->toContain('3201234567899999')
        ->not->toContain('file_path')
        ->not->toContain('kk-baru.pdf')
        ->not->toContain($d2->file_path);
});

it('AC-21: logs nothing when the replacement is rejected', function () {
    expect(fn () => app(ReplaceDocument::class)->handle($this->d1, ['file' => UploadedFile::fake()->create('a.txt', 5)], $this->f->admin))
        ->toThrow(ValidationException::class);

    expect(dokumenActivities())->toHaveCount(0);
});

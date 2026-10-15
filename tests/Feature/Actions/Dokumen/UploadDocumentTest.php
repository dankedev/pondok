<?php

use App\Actions\Dokumen\UploadDocument;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = dokumenFixture();
});

it('AC-11: stores a document on the private disk', function () {
    $document = app(UploadDocument::class)->handle(
        $this->f->s1,
        dokumenUploadData($this->f, 'KK', ['number' => ' 3201234567890001 ', 'issued_on' => '2020-01-10']),
        $this->f->admin,
    );

    $stored = Document::query()->sole();

    expect($stored->id)->toBe($document->id)
        ->and($stored->documentable->is($this->f->s1))->toBeTrue()
        ->and($stored->document_type_id)->toBe($this->f->types['KK']->id)
        ->and($stored->status)->toBe('menunggu')
        ->and($stored->uploaded_by)->toBe($this->f->admin->id)
        ->and($stored->number)->toBe('3201234567890001')
        ->and($stored->original_name)->toBe('kk.pdf')
        ->and($stored->mime_type)->toBe('application/pdf')
        ->and($stored->size_kb)->toBe(300)
        ->and($stored->issued_on->toDateString())->toBe('2020-01-10')
        ->and($stored->expires_on)->toBeNull()
        ->and($stored->file_path)->toStartWith("documents/student/{$this->f->s1->id}/")
        ->and(Storage::disk('private')->exists($stored->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($stored->verified_by)->toBeNull()
        ->and($stored->verified_at)->toBeNull()
        ->and($stored->replaced_by_id)->toBeNull();
});

it('AC-11: stores the file under a random name', function () {
    $document = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['file' => dokumenPdf('kk-ahmad-satu.pdf')]), $this->f->admin);

    expect(basename($document->file_path))->not->toContain('kk-ahmad-satu')
        ->and($document->file_path)->not->toContain('kk-ahmad-satu')
        ->and($document->original_name)->toBe('kk-ahmad-satu.pdf');
});

it('AC-11: detects the mime type from the content and rounds the size up', function () {
    $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $document = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['file' => pendaftaranRealFile('kk-asli.pdf', $content)]), $this->f->admin);

    expect($document->mime_type)->toBe('application/pdf')
        ->and($document->size_kb)->toBe(1)
        ->and(Storage::disk('private')->get($document->file_path))->toBe($content);
});

it('AC-11: truncates a long client file name to 255 characters', function () {
    $name = str_repeat('a', 300).'.pdf';
    $document = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['file' => dokumenPdf($name)]), $this->f->admin);

    expect(strlen($document->original_name))->toBe(255);
});

it('AC-11: turns a blank number into NULL', function () {
    $document = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['number' => '   ']), $this->f->admin);

    expect($document->fresh()->number)->toBeNull();
});

it('AC-12: keeps several documents of one type', function () {
    $first = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'RAPOR', ['file' => dokumenPdf('rapor-1.pdf')]), $this->f->admin);
    $snapshot = $first->fresh()->toArray();

    $second = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'RAPOR', ['file' => dokumenPdf('rapor-2.pdf')]), $this->f->admin);

    expect($this->f->s1->documents()->current()->count())->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->file_path)->not->toBe($first->file_path)
        ->and($first->fresh()->toArray())->toBe($snapshot)
        ->and(dokumenPrivateFiles())->toHaveCount(2);
});

it('AC-13: rejects invalid uploads', function (string $case, string $reason, string $field) {
    $f = $this->f;
    $owner = $f->s1;
    $data = dokumenUploadData($f);

    switch ($case) {
        case 'unknown type':
            $data['document_type_id'] = 999999;
            break;
        case 'inactive type':
            $f->types['FOTO']->update(['is_active' => false]);
            $data = dokumenUploadData($f, 'FOTO');
            break;
        case 'achievement type for a student':
            $data = dokumenUploadData($f, 'SERTIFIKAT');
            break;
        case 'student type for a guardian':
            $owner = $f->g1;
            break;
        case 'no file':
            unset($data['file']);
            break;
        case 'gif file':
            $data['file'] = UploadedFile::fake()->create('foto.gif', 20);
            break;
        case 'text file':
            $data['file'] = UploadedFile::fake()->create('catatan.txt', 20);
            break;
        case 'text content renamed to pdf':
            $data['file'] = pendaftaranRealFile('palsu.pdf', 'ini bukan pdf, hanya teks biasa');
            break;
        case 'pdf over 2 MB':
            $data['file'] = dokumenPdf('besar.pdf', 2049);
            break;
        case 'number too long':
            $data['number'] = str_repeat('1', 51);
            break;
        case 'impossible issue date':
            $data['issued_on'] = '2026-02-30';
            break;
        case 'wrong date format':
            $data['issued_on'] = '15/01/2020';
            break;
        case 'issue date tomorrow':
            $data['issued_on'] = '2026-10-16';
            break;
        case 'expiry on a type without expiry':
            $data['expires_on'] = '2030-01-01';
            break;
        case 'expiry equals issue date':
            $data = dokumenUploadData($f, 'BPJS', ['issued_on' => '2026-01-01', 'expires_on' => '2026-01-01']);
            break;
        case 'expiry before issue date':
            $data = dokumenUploadData($f, 'BPJS', ['issued_on' => '2026-01-01', 'expires_on' => '2025-12-31']);
            break;
        case 'invalid expiry date':
            $data = dokumenUploadData($f, 'BPJS', ['expires_on' => '2027-13-01']);
            break;
    }

    dokumenAssertRejected(fn () => app(UploadDocument::class)->handle($owner, $data, $f->admin), $reason, $field);

    expect(Document::query()->count())->toBe(0)
        ->and(dokumenPrivateFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'unknown type' => ['unknown type', 'document_type_not_found', 'document_type_id'],
    'inactive type' => ['inactive type', 'document_type_inactive', 'document_type_id'],
    'achievement type for a student' => ['achievement type for a student', 'document_type_owner_mismatch', 'document_type_id'],
    'student type for a guardian' => ['student type for a guardian', 'document_type_owner_mismatch', 'document_type_id'],
    'no file' => ['no file', 'file_required', 'file'],
    'gif file' => ['gif file', 'file_invalid', 'file'],
    'text file' => ['text file', 'file_invalid', 'file'],
    'text content renamed to pdf' => ['text content renamed to pdf', 'file_invalid', 'file'],
    'pdf over 2 MB' => ['pdf over 2 MB', 'file_invalid', 'file'],
    'number too long' => ['number too long', 'number_too_long', 'number'],
    'impossible issue date' => ['impossible issue date', 'date_invalid', 'issued_on'],
    'wrong date format' => ['wrong date format', 'date_invalid', 'issued_on'],
    'issue date tomorrow' => ['issue date tomorrow', 'issued_on_future', 'issued_on'],
    'expiry on a type without expiry' => ['expiry on a type without expiry', 'expiry_not_applicable', 'expires_on'],
    'expiry equals issue date' => ['expiry equals issue date', 'expires_before_issued', 'expires_on'],
    'expiry before issue date' => ['expiry before issue date', 'expires_before_issued', 'expires_on'],
    'invalid expiry date' => ['invalid expiry date', 'date_invalid', 'expires_on'],
]);

it('AC-13: accepts boundary values', function () {
    $f = $this->f;
    $action = app(UploadDocument::class);

    $big = $action->handle($f->s1, dokumenUploadData($f, 'KK', ['file' => dokumenPdf('tepat.pdf', 2048), 'number' => str_repeat('9', 50), 'issued_on' => '2026-10-15']), $f->admin);
    $jpg = $action->handle($f->s1, dokumenUploadData($f, 'FOTO', ['file' => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg')]), $f->admin);
    $png = $action->handle($f->s1, dokumenUploadData($f, 'FOTO', ['file' => UploadedFile::fake()->create('foto.png', 100, 'image/png')]), $f->admin);

    expect($big->size_kb)->toBe(2048)
        ->and($big->fresh()->number)->toBe(str_repeat('9', 50))
        ->and($big->issued_on->toDateString())->toBe('2026-10-15')
        ->and($jpg->mime_type)->toBe('image/jpeg')
        ->and($png->mime_type)->toBe('image/png')
        ->and(Document::query()->count())->toBe(3);
});

it('AC-14: accepts optional expiry dates', function () {
    $f = $this->f;

    $with = app(UploadDocument::class)->handle($f->s1, dokumenUploadData($f, 'BPJS', ['expires_on' => '2027-12-31']), $f->admin);
    $without = app(UploadDocument::class)->handle($f->s1, dokumenUploadData($f, 'BPJS'), $f->admin);
    $blank = app(UploadDocument::class)->handle($f->s1, dokumenUploadData($f, 'BPJS', ['expires_on' => '']), $f->admin);

    expect($with->fresh()->expires_on->toDateString())->toBe('2027-12-31')
        ->and($without->fresh()->expires_on)->toBeNull()
        ->and($blank->fresh()->expires_on)->toBeNull();
});

it('AC-15: checks the document owner', function (string $case) {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $action = app(UploadDocument::class);
    $kk = fn (): array => dokumenUploadData($f, 'KK');

    match ($case) {
        'unsupported owner' => dokumenAssertRejected(fn () => $action->handle(User::factory()->create(), $kk(), $f->admin), 'owner_not_supported', 'documentable'),
        'archived student' => (function () use ($f, $action, $kk): void {
            $f->s1->delete();
            dokumenAssertRejected(fn () => $action->handle($f->s1, $kk(), $f->admin), 'owner_archived', 'documentable');
        })(),
        'student of another unit' => (function () use ($f, $action, $kk): void {
            test()->actingAs(keuanganUser('admin-sekolah', ['SLTP']));
            dokumenAssertRejected(fn () => $action->handle($f->s1, $kk(), auth()->user()), 'owner_not_found', 'documentable');
        })(),
        'guardian of another unit' => (function () use ($f, $action, $walia): void {
            test()->actingAs(keuanganUser('admin-sekolah', ['SLTP']));
            dokumenAssertRejected(fn () => $action->handle($f->g1, ['document_type_id' => $walia->id, 'file' => dokumenPdf()], auth()->user()), 'owner_not_found', 'documentable');
        })(),
    };

    expect(Document::query()->count())->toBe(0)->and(dokumenPrivateFiles())->toBe([]);
})->with(['unsupported owner', 'archived student', 'student of another unit', 'guardian of another unit']);

it('AC-15: lets a scoped admin upload for owners inside the scope', function () {
    $f = $this->f;
    $staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $scoped = keuanganUser('admin-sekolah', ['SLTP']);
    $this->actingAs($scoped);
    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($f->s2->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $action = app(UploadDocument::class);

    $student = $action->handle($f->s2, dokumenUploadData($f, 'KK'), $scoped);
    $staff = $action->handle($f->t1, ['document_type_id' => $staffType->id, 'file' => dokumenPdf('staff.pdf')], $scoped);
    $wali = $action->handle($guardian, ['document_type_id' => $walia->id, 'file' => dokumenPdf('wali.pdf')], $scoped);

    expect($student->documentable_type)->toBe('student')
        ->and($staff->documentable_type)->toBe('staff')
        ->and($wali->documentable_type)->toBe('guardian')
        ->and($wali->file_path)->toStartWith("documents/guardian/{$guardian->id}/")
        ->and($staff->file_path)->toStartWith("documents/staff/{$f->t1->id}/");
});

it('AC-15: refuses a guardian that has no student at all for a scoped admin', function () {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $alone = Guardian::factory()->create();
    $scoped = keuanganUser('admin-sekolah', ['SLTP']);
    $this->actingAs($scoped);

    dokumenAssertRejected(fn () => app(UploadDocument::class)->handle($alone, ['document_type_id' => $walia->id, 'file' => dokumenPdf()], $scoped), 'owner_not_found', 'documentable');

    expect(dokumenPrivateFiles())->toBe([]);
});

it('AC-16: removes the stored file when saving fails', function () {
    $f = $this->f;
    $ghost = User::factory()->make(['id' => 999999]);

    expect(fn () => app(UploadDocument::class)->handle($f->s1, dokumenUploadData($f, 'KK'), $ghost))->toThrow(QueryException::class)
        ->and(Document::query()->count())->toBe(0)
        ->and(dokumenPrivateFiles())->toBe([]);
});

it('AC-16: removes the stored file when a creating listener throws', function () {
    $f = $this->f;
    Event::listen('eloquent.creating: '.Document::class, fn () => throw new RuntimeException('boom'));

    try {
        expect(fn () => app(UploadDocument::class)->handle($f->s1, dokumenUploadData($f, 'KK'), $f->admin))->toThrow(RuntimeException::class, 'boom');
    } finally {
        Event::forget('eloquent.creating: '.Document::class);
    }

    expect(Document::query()->count())->toBe(0)
        ->and(dokumenPrivateFiles())->toBe([])
        ->and(dokumenActivities())->toHaveCount(0);
});

it('AC-17: logs uploads without personal data', function () {
    $document = app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['number' => '3201234567890001']), $this->f->admin);

    $activity = dokumenActivities()->sole();
    $json = $activity->toJson();

    expect($activity->subject->is($document))->toBeTrue()
        ->and($activity->causer->is($this->f->admin))->toBeTrue()
        ->and($activity->description)->toBe('uploaded')
        ->and($activity->properties['document_type'])->toBe('KK')
        ->and($activity->properties['documentable_type'])->toBe('student')
        ->and($activity->properties['documentable_id'])->toBe($this->f->s1->id)
        ->and($activity->properties['status'])->toBe('menunggu')
        ->and($json)->not->toContain('3201234567890001')
        ->not->toContain('file_path')
        ->not->toContain('kk.pdf')
        ->not->toContain($document->file_path);
});

it('AC-17: writes no log for a rejected upload', function () {
    expect(fn () => app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK', ['number' => str_repeat('1', 51)]), $this->f->admin))->toThrow(ValidationException::class);

    expect(dokumenActivities())->toHaveCount(0);
});

it('AC-11: keeps the student untouched', function () {
    $before = Student::query()->find($this->f->s1->id)->toArray();

    app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'KK'), $this->f->admin);

    expect(Student::query()->find($this->f->s1->id)->toArray())->toBe($before);
});

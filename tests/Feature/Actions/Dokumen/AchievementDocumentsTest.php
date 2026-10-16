<?php

use App\Actions\Dokumen\ReplaceDocument;
use App\Actions\Dokumen\UploadDocument;
use App\Models\Achievement;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-24: scopes achievement documents', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $p3 = prestasiCreate($f, [$f->s1]);
    $d1 = prestasiDocument($f, $p1);
    $d3 = prestasiDocument($f, $p3);

    $this->actingAs($f->a2);
    expect(Document::query()->pluck('id')->all())->toBe([$d1->id]);

    $this->actingAs($f->a);
    expect(Document::query()->pluck('id')->sort()->values()->all())->toBe([$d1->id, $d3->id]);

    expect($d1->documentable)->toBeInstanceOf(Achievement::class)
        ->and($d1->documentable->is($p1))->toBeTrue()
        ->and($d1->documentable_type)->toBe('achievement');
});

it('AC-24: shows a team achievement document to every unit that has a participant', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $document = prestasiDocument($f, $team);
    $sdAdmin = keuanganUser('admin-sekolah', ['SD']);

    $this->actingAs($f->a2);
    expect(Document::query()->find($document->id))->not->toBeNull();

    $this->actingAs($sdAdmin);
    expect(Document::query()->find($document->id))->not->toBeNull();
});

it('AC-24: counts archived participants for the document scope', function () {
    $f = $this->f;
    $archived = prestasiStudent($f, 'SD');
    $achievement = prestasiCreate($f, [$archived]);
    $document = prestasiDocument($f, $achievement);
    $archived->delete();

    $this->actingAs(keuanganUser('admin-sekolah', ['SD']));
    expect(Document::query()->find($document->id))->not->toBeNull();

    $this->actingAs($f->a2);
    expect(Document::query()->find($document->id))->toBeNull();
});

it('AC-25: adds achievement to visible owner types', function () {
    $f = $this->f;

    expect(Document::visibleOwnerTypesFor($f->k))->toEqualCanonicalizing(['student', 'achievement'])
        ->and(Document::visibleOwnerTypesFor($f->k))->not->toContain('guardian')->not->toContain('staff')
        ->and(Document::visibleOwnerTypesFor($f->a))->toEqualCanonicalizing(['student', 'guardian', 'staff', 'achievement'])
        ->and(Document::visibleOwnerTypesFor($f->admin))->toContain('student', 'guardian', 'staff', 'achievement');

    $documentOnly = dashboardUserWith(['view-any:document', 'view:document']);

    expect(Document::visibleOwnerTypesFor($documentOnly))->not->toContain('achievement');
});

it('AC-26: uploads certificates for achievements', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    $document = app(UploadDocument::class)->handle($p1, ['document_type_id' => $f->types['SERTIFIKAT']->id, 'file' => dokumenPdf('sertifikat.pdf')], $f->k);

    expect($document->documentable_type)->toBe('achievement')
        ->and($document->documentable_id)->toBe($p1->id)
        ->and($document->status)->toBe('menunggu')
        ->and($document->uploaded_by)->toBe($f->k->id)
        ->and($document->file_path)->toStartWith("documents/achievement/{$p1->id}/")
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $photo = app(UploadDocument::class)->handle($p1, ['document_type_id' => $f->types['FOTO_PRESTASI']->id, 'file' => dokumenPdf('foto.pdf')], $f->k);

    expect($photo->documentType->code)->toBe('FOTO_PRESTASI');
});

it('AC-26: rejects student document types and unscoped owners', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $p3 = prestasiCreate($f, [$f->s1]);
    $this->actingAs($f->k);

    dokumenAssertRejected(fn () => app(UploadDocument::class)->handle($p1, ['document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf()], $f->k), 'document_type_owner_mismatch', 'document_type_id');

    $this->actingAs($f->k2);

    dokumenAssertRejected(fn () => app(UploadDocument::class)->handle($p3, ['document_type_id' => $f->types['SERTIFIKAT']->id, 'file' => dokumenPdf()], $f->k2), 'owner_not_found', 'documentable');

    expect(Document::withoutUnitScope()->count())->toBe(0)->and(dokumenPrivateFiles())->toBe([]);
});

it('AC-26: rejects achievement types for student owners', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    dokumenAssertRejected(fn () => app(UploadDocument::class)->handle($f->s2, ['document_type_id' => $f->types['SERTIFIKAT']->id, 'file' => dokumenPdf()], $f->k), 'document_type_owner_mismatch', 'document_type_id');
});

it('AC-26: replaces an achievement document with a new version', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $old = prestasiDocument($f, $p1);
    $this->actingAs($f->k);

    $new = app(ReplaceDocument::class)->handle($old, ['file' => dokumenPdf('baru.pdf')], $f->k);

    expect($old->fresh()->replaced_by_id)->toBe($new->id)
        ->and($new->documentable_type)->toBe('achievement')
        ->and($new->document_type_id)->toBe($old->document_type_id);
});

it('AC-26: refuses a replacement outside the unit scope', function () {
    $f = $this->f;
    $p3 = prestasiCreate($f, [$f->s1]);
    $old = prestasiDocument($f, $p3);
    $this->actingAs($f->k2);

    dokumenAssertRejected(fn () => app(ReplaceDocument::class)->handle($old, ['file' => dokumenPdf()], $f->k2), 'owner_not_found', 'documentable');

    expect($old->fresh()->replaced_by_id)->toBeNull();
});

it('AC-27: lets achievement editors upload without document create permission', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $can = fn ($user, $owner): bool => Gate::forUser($user)->allows('createFor', [Document::class, $owner]);

    expect($f->k->can('create:document'))->toBeFalse()
        ->and($can($f->k, $p1))->toBeTrue()
        ->and($can($f->a, $p1))->toBeFalse()
        ->and($can($f->k, $f->s2))->toBeFalse()
        ->and($can($f->a, $f->s2))->toBeTrue()
        ->and($can($f->k2, $team))->toBeFalse()
        ->and($can($f->k, $team))->toBeTrue()
        ->and($can($f->c, $p1))->toBeFalse();
});

it('AC-27: lets editors download certificates and reviewers verify them', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $document = prestasiDocument($f, $p1);

    expect(Gate::forUser($f->k)->allows('download', $document))->toBeTrue()
        ->and(Gate::forUser($f->a)->allows('verify', $document))->toBeTrue()
        ->and(Gate::forUser($f->k)->allows('verify', $document))->toBeFalse()
        ->and(Gate::forUser($f->c)->allows('download', $document))->toBeFalse();
});

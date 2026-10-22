<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = waliBoot();
    $this->open = dokumenType(['name' => 'Ijazah Terbuka']);
    $this->secret = dokumenType(['name' => 'Berkas Rahasia', 'is_confidential' => true]);
    $this->oldType = dokumenType(['name' => 'Versi Lama']);
    $this->guardianType = dokumenType(['name' => 'Milik Wali', 'owner' => 'wali']);
    $this->own = dokumenCreate($this->f->s1, $this->open, ['content' => 'isi-ijazah']);
});

it('AC-10: lists only current non-confidential documents of own students', function () {
    dokumenCreate($this->f->s1, $this->oldType, ['replaced_by_id' => $this->own->id]);
    dokumenCreate($this->f->s1, $this->secret);
    dokumenCreate($this->f->guardian, $this->guardianType);

    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}");

    expect($text)->toContain('Ijazah Terbuka')->not->toContain('Berkas Rahasia')->not->toContain('Milik Wali');
});

it('AC-10: downloads an own document as a private attachment from the private disk', function () {
    $response = $this->actingAs($this->f->wali)->get("/wali/dokumen/{$this->own->id}/unduh")->assertOk();

    expect($response->streamedContent())->toBe('isi-ijazah')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('AC-10: refuses replaced, confidential, guardian and missing-file documents with 404', function (string $case) {
    $document = match ($case) {
        'replaced' => dokumenCreate($this->f->s1, $this->oldType, ['replaced_by_id' => $this->own->id]),
        'confidential' => dokumenCreate($this->f->s1, $this->secret),
        'guardian' => dokumenCreate($this->f->guardian, $this->guardianType),
        'missing file' => tap(dokumenCreate($this->f->s1, $this->oldType), fn ($d) => Storage::disk('private')->delete($d->file_path)),
    };

    $this->actingAs($this->f->wali)->get("/wali/dokumen/{$this->own->id}/unduh")->assertOk();
    $this->get("/wali/dokumen/{$document->id}/unduh")->assertNotFound();
})->with(['replaced', 'confidential', 'guardian', 'missing file']);

it('AC-10: guests cannot download', function () {
    $this->get("/wali/dokumen/{$this->own->id}/unduh")->assertRedirect('/wali/masuk');
});

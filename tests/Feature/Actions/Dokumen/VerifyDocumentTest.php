<?php

use App\Actions\Dokumen\VerifyDocument;

beforeEach(function () {
    $this->f = dokumenFixture();
});

it('AC-22: verifies and corrects documents', function () {
    $document = dokumenCreate($this->f->s1, 'KK');

    $verified = app(VerifyDocument::class)->handle($document, 'valid', $this->f->admin);

    expect($verified->fresh())
        ->status->toBe('valid')
        ->verified_by->toBe($this->f->admin->id);
    expect($verified->fresh()->verified_at->toDateTimeString())->toBe(now()->toDateTimeString());

    $activity = dokumenActivities()->sole();

    expect($activity->description)->toBe('verified')
        ->and($activity->subject->is($document))->toBeTrue()
        ->and($activity->causer->is($this->f->admin))->toBeTrue()
        ->and($activity->properties['from'])->toBe('menunggu')
        ->and($activity->properties['to'])->toBe('valid');
});

it('AC-22: moves between valid and ditolak and records the latest verifier', function () {
    $document = dokumenCreate($this->f->s1, 'KK');
    $other = keuanganUser('admin-sekolah');

    app(VerifyDocument::class)->handle($document, 'valid', $this->f->admin);
    $this->travelTo(now()->addHour());
    app(VerifyDocument::class)->handle($document->fresh(), 'ditolak', $other);

    $rejected = $document->fresh();

    expect($rejected->status)->toBe('ditolak')
        ->and($rejected->verified_by)->toBe($other->id)
        ->and($rejected->verified_at->toDateTimeString())->toBe(now()->toDateTimeString());

    app(VerifyDocument::class)->handle($rejected, 'valid', $this->f->admin);

    expect($document->fresh())->status->toBe('valid')->verified_by->toBe($this->f->admin->id)
        ->and(dokumenActivities()->pluck('properties')->map(fn ($p): string => $p['from'].'>'.$p['to'])->all())->toBe(['menunggu>valid', 'valid>ditolak', 'ditolak>valid']);
});

it('AC-22: rejects a pending document directly', function () {
    $document = dokumenCreate($this->f->s1, 'KK');

    app(VerifyDocument::class)->handle($document, 'ditolak', $this->f->admin);

    expect($document->fresh())->status->toBe('ditolak')->verified_by->toBe($this->f->admin->id);
});

it('AC-22: writes no personal data to the log', function () {
    $document = dokumenCreate($this->f->s1, 'KK', ['number' => '3201234567890001']);

    app(VerifyDocument::class)->handle($document, 'valid', $this->f->admin);

    expect(dokumenActivities()->sole()->toJson())->not->toContain('3201234567890001')->not->toContain('file_path');
});

it('AC-23: rejects invalid verifications', function (string $case, string $reason) {
    $f = $this->f;
    $document = dokumenCreate($f->s1, 'KK');
    $status = 'valid';

    switch ($case) {
        case 'pending status':
            $status = 'menunggu';
            break;
        case 'cancelled status':
            $status = 'batal';
            break;
        case 'replaced document':
            $new = dokumenCreate($f->s1, 'KK');
            $document->forceFill(['replaced_by_id' => $new->id])->save();
            break;
        case 'valid again':
            $document = dokumenValid($f->s1, 'KK');
            break;
        case 'rejected again':
            $document = dokumenCreate($f->s1, 'KK', ['status' => 'ditolak', 'verified_by' => $f->admin->id, 'verified_at' => now()]);
            $status = 'ditolak';
            break;
        case 'expired document':
            $document = dokumenCreate($f->s1, 'BPJS', ['expires_on' => '2026-10-14']);
            break;
    }

    $before = $document->fresh()->toArray();

    dokumenAssertRejected(fn () => app(VerifyDocument::class)->handle($document->fresh(), $status, $f->admin), $reason, 'status');

    expect($document->fresh()->toArray())->toBe($before)
        ->and(dokumenActivities())->toHaveCount(0);
})->with([
    'pending status' => ['pending status', 'status_invalid'],
    'cancelled status' => ['cancelled status', 'status_invalid'],
    'replaced document' => ['replaced document', 'document_replaced'],
    'valid again' => ['valid again', 'status_unchanged'],
    'rejected again' => ['rejected again', 'status_unchanged'],
    'expired document' => ['expired document', 'document_expired'],
]);

it('AC-23: lets an expired document be rejected', function () {
    $document = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => '2026-10-14']);

    app(VerifyDocument::class)->handle($document, 'ditolak', $this->f->admin);

    expect($document->fresh()->status)->toBe('ditolak');
});

it('AC-23: validates a document that expires today or has no expiry', function (?string $expiresOn) {
    $document = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => $expiresOn]);

    app(VerifyDocument::class)->handle($document, 'valid', $this->f->admin);

    expect($document->fresh()->status)->toBe('valid');
})->with(['today' => '2026-10-15', 'no expiry' => null, 'future' => '2027-01-01']);

it('AC-23: ignores an expiry date on a type without expiry', function () {
    $document = dokumenCreate($this->f->s1, 'KK', ['expires_on' => '2020-01-01']);

    app(VerifyDocument::class)->handle($document, 'valid', $this->f->admin);

    expect($document->fresh()->status)->toBe('valid');
});

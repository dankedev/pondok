<?php

use App\Actions\Psb\VerifyApplicantDocument;
use App\Models\ApplicantDocument;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = pendaftaranFixture();
    $this->user = User::factory()->create();
    $this->applicant = pendaftaranApplicant();
    $this->document = pendaftaranDocument($this->applicant, $this->f->kk);
});

function pendaftaranVerify(ApplicantDocument $document, User $user, string $status, ?string $note = null): ApplicantDocument
{
    return app(VerifyApplicantDocument::class)->handle($document, $user, $status, $note);
}

it('AC-34: verifies a document as valid', function () {
    $result = pendaftaranVerify($this->document, $this->user, 'valid');

    expect($result->fresh())->status->toBe('valid')->note->toBeNull()->verified_by->toBe($this->user->id)
        ->and($result->fresh()->verified_at->timestamp)->toBe(now()->timestamp);
});

it('AC-34: rejects a document with a trimmed note', function () {
    $result = pendaftaranVerify($this->document, $this->user, 'ditolak', '  Foto buram  ');

    expect($result->fresh())->status->toBe('ditolak')->note->toBe('Foto buram')->verified_by->toBe($this->user->id);
});

it('AC-34: clears the note when a rejected document becomes valid', function () {
    pendaftaranVerify($this->document, $this->user, 'ditolak', 'Foto buram');

    pendaftaranVerify($this->document->fresh(), $this->user, 'valid', 'catatan lama');

    expect($this->document->fresh())->status->toBe('valid')->note->toBeNull();
});

it('AC-34: lets a decision change from valid to rejected', function () {
    pendaftaranVerify($this->document, $this->user, 'valid');

    pendaftaranVerify($this->document->fresh(), $this->user, 'ditolak', 'Ternyata kedaluwarsa');

    expect($this->document->fresh())->status->toBe('ditolak')->note->toBe('Ternyata kedaluwarsa');
});

it('AC-34: logs the status change with old and new values', function () {
    pendaftaranVerify($this->document, $this->user, 'ditolak', 'Foto buram');
    pendaftaranVerify($this->document->fresh(), $this->user, 'valid');

    $activity = Activity::query()->where('subject_type', $this->document->getMorphClass())->where('subject_id', $this->document->id)
        ->where('event', 'updated')->orderByDesc('id')->first();

    expect($activity)->log_name->toBe('psb')
        ->and($activity->properties['old']['status'])->toBe('ditolak')
        ->and($activity->properties['attributes']['status'])->toBe('valid');
});

it('AC-34: rejects an unknown status', function () {
    pendaftaranAssertRejected(fn () => pendaftaranVerify($this->document, $this->user, 'hapus'), 'document_status_invalid', 'status');

    expect($this->document->fresh()->status)->toBe('menunggu');
});

it('AC-34: requires a note between 1 and 500 characters to reject', function (?string $note) {
    pendaftaranAssertRejected(fn () => pendaftaranVerify($this->document, $this->user, 'ditolak', $note), 'note_required', 'note');

    expect($this->document->fresh())->status->toBe('menunggu')->note->toBeNull();
})->with([[null], [''], ['   '], [str_repeat('a', 501)]]);

it('AC-34: accepts a rejection note of exactly 500 characters', function () {
    pendaftaranVerify($this->document, $this->user, 'ditolak', str_repeat('a', 500));

    expect($this->document->fresh()->status)->toBe('ditolak');
});

it('AC-34: refuses to verify documents of closed applicants', function (string $status) {
    $this->applicant->forceFill(['status' => $status])->save();

    pendaftaranAssertRejected(fn () => pendaftaranVerify($this->document, $this->user, 'valid'), 'applicant_closed');

    expect($this->document->fresh()->status)->toBe('menunggu');
})->with(['ditolak', 'diterima', 'cadangan']);

it('AC-34: verifies documents of verified applicants', function () {
    $this->applicant->forceFill(['status' => 'diverifikasi'])->save();

    expect(pendaftaranVerify($this->document, $this->user, 'valid')->fresh()->status)->toBe('valid');
});

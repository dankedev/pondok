<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Models\IssuedLetter;

beforeEach(function () {
    $this->f = suratFixture();
    $this->letter = suratIssue($this->f);
});

it('AC-16: cancels a letter without deleting it', function () {
    $f = $this->f;
    $before = IssuedLetter::withoutGlobalScopes()->find($this->letter->id);
    $this->travelTo(now()->addHour());

    $result = app(CancelIssuedLetter::class)->handle($this->letter, $f->admin, 'Salah keperluan');
    $stored = IssuedLetter::withoutGlobalScopes()->find($this->letter->id);

    expect($result->status)->toBe('batal')
        ->and($stored->status)->toBe('batal')
        ->and($stored->cancelled_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($stored->cancelled_by)->toBe($f->admin->id)
        ->and($stored->cancel_reason)->toBe('Salah keperluan')
        ->and($stored->letter_no)->toBe($before->letter_no)
        ->and($stored->verification_code)->toBe($before->verification_code)
        ->and($stored->snapshot)->toEqual($before->snapshot)
        ->and(IssuedLetter::withoutGlobalScopes()->count())->toBe(1);

    $activities = dokumenActivities()->where('event', 'cancelled');

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->getExtraProperty('letter_no'))->toBe('001/MAK/SKA/X/2026')
        ->and($activities->first()->getExtraProperty('reason'))->toBe('Salah keperluan');
});

it('AC-16: trims the reason', function () {
    app(CancelIssuedLetter::class)->handle($this->letter, $this->f->admin, '  Salah keperluan  ');

    expect($this->letter->fresh()->cancel_reason)->toBe('Salah keperluan');
});

it('AC-16: rejects cancelling a letter twice', function () {
    $f = $this->f;
    app(CancelIssuedLetter::class)->handle($this->letter, $f->admin, 'Salah keperluan');
    $cancelledAt = $this->letter->fresh()->cancelled_at;
    $this->travelTo(now()->addDay());

    suratAssertRejected(fn () => app(CancelIssuedLetter::class)->handle($this->letter->fresh(), $f->admin, 'Alasan lain'), 'already_cancelled', 'letter');

    $stored = $this->letter->fresh();

    expect($stored->cancel_reason)->toBe('Salah keperluan')
        ->and($stored->cancelled_at->equalTo($cancelledAt))->toBeTrue()
        ->and(dokumenActivities()->where('event', 'cancelled'))->toHaveCount(1);
});

it('AC-16: rejects a stale instance of an already cancelled letter', function () {
    $f = $this->f;
    $stale = IssuedLetter::withoutGlobalScopes()->find($this->letter->id);
    app(CancelIssuedLetter::class)->handle($this->letter, $f->admin, 'Salah keperluan');

    suratAssertRejected(fn () => app(CancelIssuedLetter::class)->handle($stale, $f->admin, 'Alasan lain'), 'already_cancelled', 'letter');
});

it('AC-16: rejects invalid cancellations', function (string $reason) {
    suratAssertRejected(fn () => app(CancelIssuedLetter::class)->handle($this->letter, $this->f->admin, $reason), 'reason_invalid', 'reason');

    $stored = $this->letter->fresh();

    expect($stored->status)->toBe('terbit')
        ->and($stored->cancelled_at)->toBeNull()
        ->and($stored->cancelled_by)->toBeNull()
        ->and($stored->cancel_reason)->toBeNull()
        ->and(dokumenActivities()->where('event', 'cancelled'))->toHaveCount(0);
})->with(['empty' => [''], 'only spaces' => ['   '], '256 characters' => [str_repeat('a', 256)]]);

it('AC-16: accepts a reason of exactly 255 characters', function () {
    app(CancelIssuedLetter::class)->handle($this->letter, $this->f->admin, str_repeat('a', 255));

    expect($this->letter->fresh()->status)->toBe('batal');
});

it('AC-16: cancels a letter of its own unit for a unit-scoped user', function () {
    $f = $this->f;
    $other = suratIssue($f, student: $f->s2);
    $this->actingAs($f->scoped);

    app(CancelIssuedLetter::class)->handle($other, $f->scoped, 'Salah keperluan');

    expect(IssuedLetter::withoutGlobalScopes()->find($other->id)->status)->toBe('batal');
});

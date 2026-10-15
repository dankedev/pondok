<?php

use App\Actions\Psb\SaveAdmissionTrack;
use App\Models\AdmissionTrack;
use App\Models\FeeOverride;
use App\Models\Scholarship;

function psbSaveTrack(object $f, array $data = [], ?AdmissionTrack $track = null): AdmissionTrack
{
    return app(SaveAdmissionTrack::class)->handle(psbTrackData($f, $data), $track);
}

beforeEach(function () {
    $this->f = psbFixture();
});

it('AC-12: creates a track with normalized values', function () {
    $track = psbSaveTrack($this->f, ['code' => ' tahfidz_2 ', 'name' => ' Tahfidz Lanjutan ', 'terms' => '  ']);

    expect($track->fresh())
        ->code->toBe('TAHFIDZ_2')
        ->name->toBe('Tahfidz Lanjutan')
        ->scholarship_id->toBe($this->f->tahfidz->id)
        ->terms->toBeNull()
        ->is_active->toBeTrue();
});

it('AC-12: keeps the terms text and the inactive flag', function () {
    $track = psbSaveTrack($this->f, ['terms' => ' Hafal 5 juz ', 'is_active' => false]);

    expect($track->fresh())->terms->toBe('Hafal 5 juz')->is_active->toBeFalse();
});

it('AC-12: stores an empty scholarship as NULL', function (mixed $value) {
    expect(psbSaveTrack($this->f, ['scholarship_id' => $value])->fresh()->scholarship_id)->toBeNull();
})->with(['empty string' => [''], 'null' => [null]]);

it('AC-12: accepts a code of exactly 30 characters', function () {
    expect(psbSaveTrack($this->f, ['code' => str_repeat('A', 30)])->fresh()->code)->toHaveLength(30);
});

it('AC-12: never creates or changes fee overrides', function () {
    $track = psbSaveTrack($this->f);
    psbSaveTrack($this->f, ['name' => 'Nama baru'], $track);

    expect(FeeOverride::query()->count())->toBe(0);
});

it('AC-13: rejects an invalid track', function (Closure $overrides, string $reason, string $field) {
    $f = $this->f;
    AdmissionTrack::factory()->create(['code' => 'REGULER', 'name' => 'Reguler']);

    psbAssertRejected(fn () => psbSaveTrack($f, $overrides($f)), $reason, $field);

    expect(AdmissionTrack::query()->count())->toBe(1);
})->with([
    'empty code' => [fn () => ['code' => ''], 'code_format', 'code'],
    'code with a hyphen' => [fn () => ['code' => 'AB-1'], 'code_format', 'code'],
    'code with a space inside' => [fn () => ['code' => 'AB 1'], 'code_format', 'code'],
    'code above 30 characters' => [fn () => ['code' => str_repeat('A', 31)], 'code_format', 'code'],
    'taken code in another case' => [fn () => ['code' => 'reguler'], 'track_code_taken', 'code'],
    'blank name' => [fn () => ['name' => '  '], 'name_required', 'name'],
    'unknown scholarship' => [fn () => ['scholarship_id' => 999], 'scholarship_not_found', 'scholarship_id'],
    'inactive scholarship' => [fn (object $f) => ['scholarship_id' => $f->lama->id], 'scholarship_inactive', 'scholarship_id'],
]);

it('AC-13: updates a track without clashing with its own code', function () {
    $track = psbSaveTrack($this->f);

    $updated = psbSaveTrack($this->f, ['name' => 'Nama baru'], $track);

    expect($updated->id)->toBe($track->id)->and($track->fresh()->name)->toBe('Nama baru')->and(AdmissionTrack::query()->count())->toBe(1);
});

it('AC-13: rejects changing a track code into a taken one', function () {
    AdmissionTrack::factory()->create(['code' => 'REGULER']);
    $track = psbSaveTrack($this->f);

    psbAssertRejected(fn () => psbSaveTrack($this->f, ['code' => 'REGULER'], $track), 'track_code_taken', 'code');

    expect($track->fresh()->code)->toBe('TAHFIDZ_2');
});

it('AC-13: keeps an inactive scholarship that is already linked', function () {
    $track = psbSaveTrack($this->f);
    $this->f->tahfidz->update(['is_active' => false]);

    $updated = psbSaveTrack($this->f, ['name' => 'Tahfidz Baru'], $track->fresh());

    expect($updated->fresh())->name->toBe('Tahfidz Baru')->scholarship_id->toBe($this->f->tahfidz->id);
});

it('AC-13: rejects switching to another inactive scholarship', function () {
    $track = psbSaveTrack($this->f);
    $this->f->tahfidz->update(['is_active' => false]);

    psbAssertRejected(fn () => psbSaveTrack($this->f, ['scholarship_id' => $this->f->lama->id], $track->fresh()), 'scholarship_inactive', 'scholarship_id');

    expect($track->fresh()->scholarship_id)->toBe($this->f->tahfidz->id);
});

it('AC-13: allows switching to another active scholarship', function () {
    $track = psbSaveTrack($this->f);
    $other = Scholarship::factory()->create();

    psbSaveTrack($this->f, ['scholarship_id' => $other->id], $track);

    expect($track->fresh()->scholarship_id)->toBe($other->id);
});

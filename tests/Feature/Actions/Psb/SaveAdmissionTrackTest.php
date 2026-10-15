<?php

use App\Actions\Psb\SaveAdmissionTrack;
use App\Models\AdmissionTrack;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Scholarship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

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

/**
 * Id jenis biaya terpetakan pada jalur, urut naik.
 *
 * @return list<int>
 */
function seleksiMappedIds(AdmissionTrack $track): array
{
    return DB::table('admission_track_fee_type')->where('admission_track_id', $track->id)->orderBy('fee_type_id')->pluck('fee_type_id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * Activity jalur urut id.
 *
 * @return Collection<int, Activity>
 */
function seleksiTrackActivities(AdmissionTrack $track): Collection
{
    return Activity::query()->where('subject_type', $track->getMorphClass())->where('subject_id', $track->id)->orderBy('id')->get();
}

/**
 * @return array{pangkal: FeeType, spp: FeeType, kitab: FeeType}
 */
function seleksiTrackFeeTypes(): array
{
    return [
        'pangkal' => psbFeeType('saat_diterima', ['code' => 'UANG_PANGKAL', 'name' => 'Uang pangkal', 'billing_group' => 'PSB_TERIMA']),
        'spp' => FeeType::factory()->monthly()->create(['code' => 'SPP', 'name' => 'SPP']),
        'kitab' => psbFeeType('saat_diterima', ['code' => 'KITAB', 'name' => 'Kitab', 'billing_group' => 'PSB_KITAB']),
    ];
}

it('AC-2 (F011): maps fee types to a track with exactly one activity', function () {
    $types = seleksiTrackFeeTypes();
    $track = psbSaveTrack($this->f);
    $before = seleksiTrackActivities($track)->count();

    psbSaveTrack($this->f, ['fee_type_ids' => [$types['spp']->id, $types['pangkal']->id]], $track->fresh());

    $expected = [$types['pangkal']->id, $types['spp']->id];
    $activities = seleksiTrackActivities($track);
    $activity = $activities->last();

    expect(seleksiMappedIds($track))->toBe($expected)
        ->and($activities)->toHaveCount($before + 1)
        ->and($activity)->log_name->toBe('psb')->event->toBe('updated')
        ->and($activity->properties['old']['fee_type_ids'])->toBe([])
        ->and($activity->properties['attributes']['fee_type_ids'])->toBe($expected);
});

it('AC-2 (F011): writes one activity when the scholarship and the mapping change together', function () {
    $types = seleksiTrackFeeTypes();
    $track = psbSaveTrack($this->f);
    $other = Scholarship::factory()->create();
    $before = seleksiTrackActivities($track)->count();

    psbSaveTrack($this->f, ['scholarship_id' => $other->id, 'fee_type_ids' => [$types['kitab']->id]], $track->fresh());

    $activities = seleksiTrackActivities($track);

    expect($activities)->toHaveCount($before + 1)
        ->and($activities->last()->event)->toBe('updated')
        ->and($activities->last()->properties['attributes']['scholarship_id'])->toBe($other->id)
        ->and($activities->last()->properties['old']['scholarship_id'])->toBe($this->f->tahfidz->id)
        ->and($activities->last()->properties['attributes']['fee_type_ids'])->toBe([$types['kitab']->id]);
});

it('AC-2 (F011): creates a track with a mapping and one created activity', function () {
    $types = seleksiTrackFeeTypes();

    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id, $types['spp']->id]]);
    $activities = seleksiTrackActivities($track);

    expect(seleksiMappedIds($track))->toBe([$types['pangkal']->id, $types['spp']->id])
        ->and($activities)->toHaveCount(1)
        ->and($activities->sole())->log_name->toBe('psb')->event->toBe('created')
        ->and($activities->sole()->properties['attributes']['fee_type_ids'])->toBe([$types['pangkal']->id, $types['spp']->id])
        ->and($activities->sole()->properties['attributes']['code'])->toBe('TAHFIDZ_2');
});

it('AC-2 (F011): leaves the fee type key out of the activity when only the name changes', function () {
    $types = seleksiTrackFeeTypes();
    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id]]);
    $before = seleksiTrackActivities($track)->count();

    psbSaveTrack($this->f, ['name' => 'Nama baru', 'fee_type_ids' => [$types['pangkal']->id]], $track->fresh());

    $activities = seleksiTrackActivities($track);

    expect($activities)->toHaveCount($before + 1)
        ->and($activities->last()->properties['attributes'])->toHaveKey('name')->not->toHaveKey('fee_type_ids')
        ->and($activities->last()->properties['old'])->not->toHaveKey('fee_type_ids')
        ->and(seleksiMappedIds($track))->toBe([$types['pangkal']->id]);
});

it('AC-2 (F011): writes no activity when nothing changes', function () {
    $types = seleksiTrackFeeTypes();
    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['spp']->id, $types['pangkal']->id]]);

    expect(seleksiMappedIds($track))->toBe([$types['pangkal']->id, $types['spp']->id]);

    $before = Activity::query()->count();

    psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id, $types['spp']->id]], $track->fresh());
    psbSaveTrack($this->f, [], $track->fresh());

    expect(Activity::query()->count())->toBe($before);
});

it('AC-2 (F011): keeps the mapping when the key is missing and clears it with an empty array', function () {
    $types = seleksiTrackFeeTypes();
    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id, $types['kitab']->id]]);

    psbSaveTrack($this->f, ['name' => 'Tanpa kunci'], $track->fresh());

    expect(seleksiMappedIds($track))->toBe([$types['pangkal']->id, $types['kitab']->id]);

    psbSaveTrack($this->f, ['name' => 'Tanpa kunci', 'fee_type_ids' => []], $track->fresh());

    expect(seleksiMappedIds($track))->toBe([])
        ->and(seleksiTrackActivities($track)->last()->properties['attributes']['fee_type_ids'])->toBe([])
        ->and(seleksiTrackActivities($track)->last()->properties['old']['fee_type_ids'])->toBe([$types['pangkal']->id, $types['kitab']->id]);
});

it('AC-2 (F011): accepts acceptance, monthly and yearly fee types', function () {
    $types = seleksiTrackFeeTypes();
    $yearly = FeeType::factory()->create(['frequency' => 'tahunan', 'billing_group' => 'TAHUNAN']);

    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id, $types['spp']->id, $yearly->id]]);

    expect(seleksiMappedIds($track))->toHaveCount(3);
});

it('AC-2 (F011): rejects an invalid mapping without changing anything', function (Closure $ids, string $reason) {
    $types = seleksiTrackFeeTypes();
    $daftar = psbFeeType('saat_daftar', ['code' => 'FORMULIR', 'name' => 'Formulir', 'billing_group' => 'PSB_DAFTAR']);
    $manual = FeeType::factory()->create(['frequency' => 'manual', 'billing_group' => 'MANUAL']);
    $track = psbSaveTrack($this->f, ['fee_type_ids' => [$types['pangkal']->id]]);
    $activities = Activity::query()->count();

    psbAssertRejected(fn () => psbSaveTrack($this->f, ['name' => 'Ganti', 'fee_type_ids' => $ids($types, $daftar, $manual)], $track->fresh()), $reason, 'fee_type_ids');

    expect(seleksiMappedIds($track))->toBe([$types['pangkal']->id])
        ->and($track->fresh()->name)->toBe('Tahfidz Lanjutan')
        ->and(Activity::query()->count())->toBe($activities);
})->with([
    'unknown id' => [fn (array $types, FeeType $daftar, FeeType $manual): array => [$types['kitab']->id, 999999], 'track_fee_type_not_found'],
    'saat_daftar' => [fn (array $types, FeeType $daftar, FeeType $manual): array => [$types['kitab']->id, $daftar->id], 'track_fee_type_invalid'],
    'manual' => [fn (array $types, FeeType $daftar, FeeType $manual): array => [$manual->id], 'track_fee_type_invalid'],
]);

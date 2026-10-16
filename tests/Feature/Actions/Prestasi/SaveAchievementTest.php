<?php

use App\Models\AcademicYear;
use App\Models\Achievement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Perubahan masukan untuk kasus gagal AC-10 (kunci dataset => override).
 *
 * @return array<string, mixed>
 */
function prestasiInvalidOverrides(object $f, string $key): array
{
    $many = fn (int $count): array => collect(range(1, $count))
        ->map(fn (): array => ['student_id' => prestasiStudent($f)->id, 'rank' => 'Peserta'])->all();

    return match ($key) {
        'category' => ['category' => 'sains'],
        'title blank' => ['title' => '  '],
        'title long' => ['title' => str_repeat('a', 256)],
        'event blank' => ['event_name' => ''],
        'event long' => ['event_name' => str_repeat('a', 256)],
        'organizer long' => ['organizer' => str_repeat('a', 256)],
        'level' => ['level' => 'dunia'],
        'kind' => ['kind' => 'tim'],
        'impossible date' => ['achieved_on' => '2026-02-30'],
        'loose date' => ['achieved_on' => '2026-8-17'],
        'text date' => ['achieved_on' => 'kemarin'],
        'future' => ['achieved_on' => '2026-10-16'],
        'no year' => ['achieved_on' => '2024-01-10'],
        'no participants' => ['participants' => []],
        'too many' => ['kind' => 'beregu', 'participants' => $many(51)],
        'duplicate' => ['participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1'], ['student_id' => $f->s2->id, 'rank' => 'Juara 2']]],
        'individual two' => ['participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1'], ['student_id' => $f->s3->id, 'rank' => 'Juara 1']]],
        'team one' => ['kind' => 'beregu'],
        'unknown student' => ['participants' => [['student_id' => 999999, 'rank' => 'Juara 1']]],
        'archived student' => (function () use ($f): array {
            $archived = prestasiStudent($f);
            $archived->delete();

            return ['participants' => [['student_id' => $archived->id, 'rank' => 'Juara 1']]];
        })(),
        'rank blank' => ['participants' => [['student_id' => $f->s2->id, 'rank' => '  ']]],
        'rank long' => ['participants' => [['student_id' => $f->s2->id, 'rank' => str_repeat('a', 51)]]],
        'role long' => ['participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => str_repeat('a', 51)]]],
    };
}

it('AC-8: records an individual achievement', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, ['title' => '  Juara 1 MTQ Tahfidz 10 Juz  ', 'organizer' => '  ']), $f->k);

    $row = Achievement::withoutUnitScope()->sole();

    expect($saved->is($row))->toBeTrue()
        ->and($row->title)->toBe('Juara 1 MTQ Tahfidz 10 Juz')
        ->and($row->organizer)->toBeNull()
        ->and($row->academic_year_id)->toBe($f->y2->id)
        ->and($row->recorded_by)->toBe($f->k->id)
        ->and($row->verified_by)->toBeNull()
        ->and($row->is_public)->toBeFalse()
        ->and($row->category)->toBe('tahfidz')
        ->and($row->kind)->toBe('individu')
        ->and($row->achieved_on->toDateString())->toBe('2026-08-17')
        ->and(prestasiPivot($row))->toBe([$f->s2->id => ['Juara 1', null]])
        ->and($saved->relationLoaded('students'))->toBeTrue();
});

it('AC-8: keeps organizer, is_public and trimmed participant text', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, [
        'organizer' => ' Kemenag Kabupaten ',
        'is_public' => true,
        'participants' => [['student_id' => $f->s2->id, 'rank' => '  Juara 1  ', 'role' => '   ']],
    ]), $f->k);

    expect($saved->fresh())->organizer->toBe('Kemenag Kabupaten')->is_public->toBeTrue()
        ->and(prestasiPivot($saved))->toBe([$f->s2->id => ['Juara 1', null]]);
});

it('AC-9: records a team achievement and derives the academic year', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, [
        'kind' => 'beregu',
        'participants' => [
            ['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => 'Ketua tim'],
            ['student_id' => $f->s3->id, 'rank' => 'Juara 1'],
        ],
    ]), $f->k);

    expect(prestasiPivot($saved))->toBe([$f->s2->id => ['Juara 1', 'Ketua tim'], $f->s3->id => ['Juara 1', null]])
        ->and($saved->kind)->toBe('beregu');
});

it('AC-9: treats both academic year bounds as inclusive (:date)', function (string $date, string $year) {
    $f = $this->f;
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, ['achieved_on' => $date]), $f->k);

    expect($saved->fresh()->academicYear->name)->toBe($year);
})->with([
    'mid year' => ['2026-03-10', '2025/2026'],
    'last day' => ['2026-06-30', '2025/2026'],
    'first day of next' => ['2026-07-01', '2026/2027'],
    'first day' => ['2025-07-01', '2025/2026'],
]);

it('AC-9: picks the academic year with the latest start when ranges overlap', function () {
    $f = $this->f;
    $special = AcademicYear::factory()->create(['name' => '2026/2027 Khusus', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, ['achieved_on' => '2026-08-17']), $f->k);

    expect($saved->fresh()->academic_year_id)->toBe($special->id);
});

it('AC-10: rejects invalid achievements without writing (:key)', function (string $key, string $reason, string $field) {
    $f = $this->f;
    $this->actingAs($f->k);
    $overrides = prestasiInvalidOverrides($f, $key);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, $overrides), $f->k), $reason, $field);

    expect(Achievement::withoutUnitScope()->count())->toBe(0)
        ->and(DB::table('achievement_student')->count())->toBe(0);
})->with([
    'category' => ['category', 'category_invalid', 'category'],
    'title blank' => ['title blank', 'title_required', 'title'],
    'title long' => ['title long', 'too_long', 'title'],
    'event blank' => ['event blank', 'event_name_required', 'event_name'],
    'event long' => ['event long', 'too_long', 'event_name'],
    'organizer long' => ['organizer long', 'too_long', 'organizer'],
    'level' => ['level', 'level_invalid', 'level'],
    'kind' => ['kind', 'kind_invalid', 'kind'],
    'impossible date' => ['impossible date', 'date_invalid', 'achieved_on'],
    'loose date' => ['loose date', 'date_invalid', 'achieved_on'],
    'text date' => ['text date', 'date_invalid', 'achieved_on'],
    'future' => ['future', 'achieved_on_future', 'achieved_on'],
    'no year' => ['no year', 'academic_year_not_found', 'achieved_on'],
    'no participants' => ['no participants', 'participants_required', 'participants'],
    'too many' => ['too many', 'participants_too_many', 'participants'],
    'duplicate' => ['duplicate', 'participant_duplicate', 'participants'],
    'individual two' => ['individual two', 'individual_single', 'participants'],
    'team one' => ['team one', 'team_too_small', 'participants'],
    'unknown student' => ['unknown student', 'student_not_found', 'participants'],
    'archived student' => ['archived student', 'student_archived', 'participants'],
    'rank blank' => ['rank blank', 'rank_required', 'participants'],
    'rank long' => ['rank long', 'too_long', 'participants'],
    'role long' => ['role long', 'too_long', 'participants'],
]);

it('AC-10: accepts the exact length limits', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $saved = prestasiSave(prestasiData($f, [
        'title' => str_repeat('a', 255),
        'event_name' => str_repeat('b', 255),
        'organizer' => str_repeat('c', 255),
        'achieved_on' => '2026-10-15',
        'participants' => [['student_id' => $f->s2->id, 'rank' => str_repeat('d', 50), 'role' => str_repeat('e', 50)]],
    ]), $f->k);

    expect(mb_strlen($saved->fresh()->title))->toBe(255)
        ->and(prestasiPivot($saved)[$f->s2->id])->toBe([str_repeat('d', 50), str_repeat('e', 50)]);
});

it('AC-10: accepts exactly fifty participants', function () {
    $f = $this->f;
    $this->actingAs($f->k);
    $participants = collect(range(1, 50))->map(fn (): array => ['student_id' => prestasiStudent($f)->id, 'rank' => 'Peserta'])->all();

    $saved = prestasiSave(prestasiData($f, ['kind' => 'beregu', 'participants' => $participants]), $f->k);

    expect(DB::table('achievement_student')->where('achievement_id', $saved->id)->count())->toBe(50);
});

it('AC-10: rejects a date that falls between academic years', function () {
    $f = $this->f;
    AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-07-15', 'ends_on' => '2028-06-30']);
    $this->travelTo(CarbonImmutable::parse('2027-07-20 10:00', 'Asia/Jakarta'));
    $this->actingAs($f->k);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, ['achieved_on' => '2027-07-10']), $f->k), 'academic_year_not_found', 'achieved_on');

    expect(Achievement::withoutUnitScope()->count())->toBe(0);
});

it('AC-10: reports the first error only and writes nothing when a later participant fails', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, [
        'category' => 'sains',
        'title' => '',
    ]), $f->k), 'category_invalid', 'category');

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, [
        'kind' => 'beregu',
        'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1'], ['student_id' => 999999, 'rank' => 'Juara 1']],
    ]), $f->k), 'student_not_found', 'participants');

    expect(Achievement::withoutUnitScope()->count())->toBe(0)->and(DB::table('achievement_student')->count())->toBe(0);
});

it('AC-11: limits participants to the unit scope', function () {
    $f = $this->f;
    $this->actingAs($f->k2);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, ['participants' => [['student_id' => $f->s1->id, 'rank' => 'Juara 1']]]), $f->k2), 'student_not_found', 'participants');

    expect(Achievement::withoutUnitScope()->count())->toBe(0);

    $saved = prestasiSave(prestasiData($f), $f->k2);

    expect($saved->recorded_by)->toBe($f->k2->id);
});

it('AC-11: refuses to edit a team achievement that is not fully in scope', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s1, $f->s2], ['title' => 'Lomba tim']);
    $this->actingAs($f->k2);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, [
        'title' => 'Diubah',
        'kind' => 'beregu',
        'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1'], ['student_id' => $f->s3->id, 'rank' => 'Juara 1']],
    ]), $f->k2, $team), 'outside_scope', 'achievement');

    $fresh = Achievement::withoutUnitScope()->findOrFail($team->id);

    expect($fresh->title)->toBe('Lomba tim')
        ->and(array_keys(prestasiPivot($fresh)))->toBe([$f->s1->id, $f->s2->id]);
});

it('AC-11: does not filter units without a logged-in user', function () {
    $f = $this->f;
    $this->actingAs($f->k2);
    Auth::logout();

    $saved = prestasiSave(prestasiData($f, ['participants' => [['student_id' => $f->s1->id, 'rank' => 'Juara 1']]]), $f->k);

    expect(prestasiPivot($saved))->toBe([$f->s1->id => ['Juara 1', null]]);
});

it('AC-12: resets verification on a changed title', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Judul lama']);
    $this->actingAs($f->admin);

    $saved = prestasiSave(prestasiData($f, ['title' => 'Judul baru']), $f->admin, $achievement);

    expect($saved->fresh())->verified_by->toBeNull()->title->toBe('Judul baru')->recorded_by->toBe($f->k->id);
});

it('AC-12: resets verification on any substantive change (:key)', function (string $key) {
    $f = $this->f;
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 1', 'Ketua']], ['verified' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz', 'organizer' => 'Kemenag']);
    $this->actingAs($f->admin);
    $base = prestasiData($f, ['organizer' => 'Kemenag', 'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => 'Ketua']]]);

    $changes = [
        'category' => ['category' => 'seni'],
        'event' => ['event_name' => 'Acara lain'],
        'organizer' => ['organizer' => 'Lain'],
        'level' => ['level' => 'provinsi'],
        'date' => ['achieved_on' => '2026-08-18'],
        'rank' => ['participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 2', 'role' => 'Ketua']]],
        'role' => ['participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => 'Anggota']]],
        'participant' => ['participants' => [['student_id' => $f->s3->id, 'rank' => 'Juara 1', 'role' => 'Ketua']]],
        'kind' => ['kind' => 'beregu', 'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => 'Ketua'], ['student_id' => $f->s3->id, 'rank' => 'Juara 1']]],
    ];

    prestasiSave([...$base, ...$changes[$key]], $f->admin, $achievement);

    expect($achievement->fresh()->verified_by)->toBeNull();
})->with(['category', 'event', 'organizer', 'level', 'date', 'rank', 'role', 'participant', 'kind']);

it('AC-12: keeps verification when only is_public changes or nothing changes', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $this->actingAs($f->admin);

    prestasiSave(prestasiData($f, ['is_public' => true]), $f->admin, $achievement);

    expect($achievement->fresh())->verified_by->toBe($f->a->id)->is_public->toBeTrue();

    prestasiSave(prestasiData($f, ['title' => '  Juara 1 MTQ Tahfidz 10 Juz ', 'is_public' => true]), $f->admin, $achievement);

    expect($achievement->fresh()->verified_by)->toBe($f->a->id);
});

it('AC-12: does not verify an unverified achievement when editing it', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['title' => 'Lama']);
    $this->actingAs($f->admin);

    prestasiSave(prestasiData($f, ['title' => 'Baru']), $f->admin, $achievement);

    expect($achievement->fresh())->verified_by->toBeNull()->title->toBe('Baru');
});

it('AC-12: replaces participants on edit', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    prestasiSave(prestasiData($f, ['participants' => [['student_id' => $f->s3->id, 'rank' => 'Juara 3']]]), $f->k, $achievement);

    expect(prestasiPivot($achievement))->toBe([$f->s3->id => ['Juara 3', null]]);
});

it('AC-12: keeps an archived participant on edit but refuses to add one elsewhere', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s2, $f->s3], ['title' => 'Tim']);
    $f->s3->delete();
    $this->actingAs($f->k);
    $data = prestasiData($f, [
        'title' => 'Tim diubah',
        'kind' => 'beregu',
        'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1'], ['student_id' => $f->s3->id, 'rank' => 'Juara 1']],
    ]);

    $saved = prestasiSave($data, $f->k, $team);

    expect($saved->fresh()->title)->toBe('Tim diubah')
        ->and(array_keys(prestasiPivot($saved)))->toBe([$f->s2->id, $f->s3->id]);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, ['participants' => [['student_id' => $f->s3->id, 'rank' => 'Juara 1']]]), $f->k), 'student_archived', 'participants');
});

it('AC-12: moves the academic year when the date changes', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    prestasiSave(prestasiData($f, ['achieved_on' => '2026-03-10']), $f->k, $achievement);

    expect($achievement->fresh())->academic_year_id->toBe($f->y1->id);
});

it('AC-13: keeps published achievements public', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['public' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $post = kontenPost(['achievement_id' => $achievement->id, 'status' => 'terbit', 'published_at' => now()->subDay()]);
    $this->actingAs($f->k);

    prestasiAssertRejected(fn () => prestasiSave(prestasiData($f, ['is_public' => false]), $f->k, $achievement), 'public_post_exists', 'is_public');

    expect($achievement->fresh()->is_public)->toBeTrue();

    $post->forceFill(['status' => 'draft', 'published_at' => null])->save();

    prestasiSave(prestasiData($f, ['is_public' => false]), $f->k, $achievement);

    expect($achievement->fresh()->is_public)->toBeFalse();
});

it('AC-13: allows making an achievement private when its published post was deleted', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['public' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    kontenPost(['achievement_id' => $achievement->id, 'status' => 'terbit', 'published_at' => now()->subDay()])->delete();
    $this->actingAs($f->k);

    prestasiSave(prestasiData($f, ['is_public' => false]), $f->k, $achievement);

    expect($achievement->fresh()->is_public)->toBeFalse();
});

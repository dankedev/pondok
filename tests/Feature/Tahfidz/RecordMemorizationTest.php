<?php

use App\Actions\Tahfidz\DeleteMemorizationRecord;
use App\Actions\Tahfidz\RecordMemorization;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\MemorizationRecord;
use App\Models\Staff;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->f = tahfidzBoot();
    $this->admin = tahfidzAdmin();
    [$this->halaqah, $this->student] = tahfidzHalaqahWithMember($this->f);
});

it('AC-5: records a ziyadah and stores the actor and the range', function () {
    $record = tahfidzRecord($this->admin, $this->halaqah, $this->student);

    expect($record->student_id)->toBe($this->student->id)
        ->and($record->halaqah_id)->toBe($this->halaqah->id)
        ->and($record->recorded_by)->toBe($this->admin->id)
        ->and($record->recorded_on->toDateString())->toBe('2026-10-14')
        ->and($record->type)->toBe('ziyadah')
        ->and([$record->from_surah_id, $record->from_ayah, $record->to_surah_id, $record->to_ayah])->toBe([78, 1, 78, 5])
        ->and((string) $record->pages)->toBe('0.5')
        ->and(MemorizationRecord::query()->count())->toBe(1);
});

it('AC-5: accepts a forward range across surahs and the last ayat of a surah', function () {
    $across = tahfidzRecord($this->admin, $this->halaqah, $this->student, ['from_surah_id' => 78, 'from_ayah' => 30, 'to_surah_id' => 79, 'to_ayah' => 46]);
    $last = tahfidzRecord($this->admin, $this->halaqah, $this->student, ['from_surah_id' => 114, 'from_ayah' => 6, 'to_surah_id' => 114, 'to_ayah' => 6]);

    expect($across->exists)->toBeTrue()->and($last->exists)->toBeTrue();
});

it('AC-5: rejects an invalid range, date or listener', function (array $overrides, array $fields) {
    tahfidzRejected(fn () => tahfidzRecord($this->admin, $this->halaqah, $this->student, $overrides), $fields);

    expect(MemorizationRecord::query()->count())->toBe(0);
})->with([
    'ayat zero' => [['from_ayah' => 0], ['from_ayah']],
    'to ayat beyond the surah' => [['to_ayah' => 41], ['to_ayah']],
    'from ayat beyond the surah' => [['from_surah_id' => 114, 'from_ayah' => 7, 'to_surah_id' => 114, 'to_ayah' => 7], ['from_ayah']],
    'surah 115' => [['to_surah_id' => 115, 'to_ayah' => 1], ['to_surah_id']],
    'to before from in one surah' => [['from_ayah' => 10, 'to_ayah' => 5], ['to_ayah', 'to_surah_id']],
    'to before from across surahs' => [['from_surah_id' => 79, 'from_ayah' => 1, 'to_surah_id' => 78, 'to_ayah' => 40], ['to_ayah', 'to_surah_id']],
    'future date' => [['recorded_on' => '2026-10-16'], ['recorded_on']],
]);

it('AC-5: rejects a student that is not a member of the submitted halaqah on that date', function (Closure $arrange, array $overrides, array $fields) {
    $halaqah = $arrange($this);

    tahfidzRejected(fn () => tahfidzRecord($this->admin, $halaqah, $this->student, $overrides), $fields);

    expect(MemorizationRecord::query()->count())->toBe(0);
})->with([
    'before joined_on' => [fn ($t) => $t->halaqah, ['recorded_on' => '2026-06-30'], ['recorded_on', 'student_id']],
    'on left_on' => [function ($t) {
        HalaqahMember::query()->where('student_id', $t->student->id)->update(['left_on' => '2026-10-12']);

        return $t->halaqah;
    }, ['recorded_on' => '2026-10-12'], ['recorded_on', 'student_id']],
    'member of another halaqah' => [fn ($t) => tahfidzHalaqah($t->f), [], ['student_id', 'halaqah_id']],
    'inactive halaqah' => [function ($t) {
        Halaqah::query()->whereKey($t->halaqah->id)->update(['is_active' => false]);

        return $t->halaqah->fresh();
    }, [], ['halaqah_id']],
    'inactive listener' => [function ($t) {
        Staff::query()->whereKey($t->halaqah->teacher_staff_id)->update(['is_active' => false]);

        return $t->halaqah;
    }, [], ['listener_staff_id']],
]);

it('AC-5: accepts a record on the day before left_on', function () {
    HalaqahMember::query()->where('student_id', $this->student->id)->update(['left_on' => '2026-10-12']);

    expect(tahfidzRecord($this->admin, $this->halaqah, $this->student, ['recorded_on' => '2026-10-11'])->exists)->toBeTrue();
});

it('AC-5: defaults the listener to the staff row of a teacher who records', function () {
    $ustadz = tahfidzUstadz();
    [$halaqah, $student] = tahfidzHalaqahWithMember($this->f, $ustadz->staff);
    $data = tahfidzRecordData($halaqah, $student);
    unset($data['listener_staff_id']);

    $record = app(RecordMemorization::class)->handle($ustadz, $data);

    expect($record->listener_staff_id)->toBe($ustadz->staff->id);
});

it('AC-5: refuses roles without create:memorization-record', function () {
    expect(fn () => tahfidzRecord(asramaUser('bendahara'), $this->halaqah, $this->student))->toThrow(AuthorizationException::class)
        ->and(MemorizationRecord::query()->count())->toBe(0);
});

it('AC-7: deleting the closing record turns hafal back into proses', function () {
    $first = tahfidzRecord($this->admin, $this->halaqah, $this->student, ['recorded_on' => '2026-10-01', 'from_surah_id' => 100, 'from_ayah' => 1, 'to_surah_id' => 114, 'to_ayah' => 6]);
    $closing = tahfidzRecord($this->admin, $this->halaqah, $this->student, ['recorded_on' => '2026-10-02', 'from_surah_id' => 78, 'from_ayah' => 1, 'to_surah_id' => 99, 'to_ayah' => 8]);

    expect(tahfidzProgress($this->student))->toBe([30 => ['hafal', '2026-10-02']]);

    app(DeleteMemorizationRecord::class)->handle($this->admin, $closing);

    expect(MemorizationRecord::query()->whereKey($closing->id)->exists())->toBeFalse()
        ->and(MemorizationRecord::query()->whereKey($first->id)->exists())->toBeTrue()
        ->and(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);
});

it('AC-7: deleting the only record removes the progress row', function () {
    $record = tahfidzRecord($this->admin, $this->halaqah, $this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);

    app(DeleteMemorizationRecord::class)->handle($this->admin, $record);

    expect(tahfidzProgress($this->student))->toBe([]);
});

it('AC-7: refuses to delete without delete:memorization-record', function () {
    $record = tahfidzRecord($this->admin, $this->halaqah, $this->student);

    expect(fn () => app(DeleteMemorizationRecord::class)->handle(asramaUser('bendahara'), $record))->toThrow(AuthorizationException::class)
        ->and(MemorizationRecord::query()->count())->toBe(1);
});

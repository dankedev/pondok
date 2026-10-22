<?php

use App\Models\MemorizationRecord;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = tahfidzBoot();
    [$this->halaqah, $this->student] = tahfidzHalaqahWithMember($this->f);
});

function tahfidzRaw(string $on, int $fromSurah, int $fromAyah, int $toSurah, int $toAyah, array $extra = []): void
{
    tahfidzRawRecord(test()->halaqah, test()->student, [
        'recorded_on' => $on, 'from_surah_id' => $fromSurah, 'from_ayah' => $fromAyah, 'to_surah_id' => $toSurah, 'to_ayah' => $toAyah, ...$extra,
    ]);
}

it('AC-6: a partial ziyadah of juz 30 is proses', function () {
    tahfidzRaw('2026-10-10', 78, 1, 78, 40);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);
});

it('AC-6: several backward records covering 78:1-114:6 make juz 30 hafal on the closing date', function () {
    tahfidzRaw('2026-10-01', 100, 1, 114, 6);
    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);

    tahfidzRaw('2026-10-05', 90, 1, 99, 8);
    tahfidzRaw('2026-10-08', 78, 1, 89, 30);
    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['hafal', '2026-10-08']]);
});

it('AC-6: overlapping records close a juz but a one-ayat gap does not', function () {
    tahfidzRaw('2026-10-01', 78, 1, 100, 5);
    tahfidzRaw('2026-10-02', 99, 1, 114, 6);
    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['hafal', '2026-10-02']]);

    MemorizationRecord::query()->where('recorded_on', '2026-10-02')->update(['from_surah_id' => 100, 'from_ayah' => 7, 'to_surah_id' => 114, 'to_ayah' => 6]);
    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);
});

it('AC-6: a record across surahs closes every juz it covers completely', function () {
    tahfidzRaw('2026-10-03', 1, 1, 3, 92);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([1 => ['hafal', '2026-10-03'], 2 => ['hafal', '2026-10-03'], 3 => ['hafal', '2026-10-03']]);
});

it('AC-6: 2:141-2:142 marks juz 1 and juz 2 as proses', function () {
    tahfidzRaw('2026-10-03', 2, 141, 2, 142);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([1 => ['proses', null], 2 => ['proses', null]]);
});

it('AC-6: ulang grade, murajaah and tasmi do not change the progress', function () {
    tahfidzRaw('2026-10-03', 78, 1, 114, 6, ['grade' => 'ulang']);
    tahfidzRaw('2026-10-04', 78, 1, 114, 6, ['type' => 'murajaah']);
    tahfidzRaw('2026-10-05', 78, 1, 114, 6, ['type' => 'tasmi']);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([]);
});

it('AC-7: refreshing twice gives the same rows without duplicates', function () {
    tahfidzRaw('2026-10-01', 78, 1, 114, 6);
    tahfidzRaw('2026-10-02', 2, 141, 2, 142);

    tahfidzRefresh($this->student);
    $once = tahfidzProgress($this->student);
    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe($once)
        ->and($once)->toBe([1 => ['proses', null], 2 => ['proses', null], 30 => ['hafal', '2026-10-01']])
        ->and(DB::table('student_juz_progress')->where('student_id', $this->student->id)->count())->toBe(3);
});

it('AC-7: recomputes after rows were removed and drops juz that no longer have records', function () {
    tahfidzRaw('2026-10-01', 78, 1, 78, 10);
    tahfidzRefresh($this->student);
    MemorizationRecord::query()->delete();

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([]);
});

it('AC-8: a passed exam marks its juz lulus_ujian, completed on the hafal date or else the exam date', function () {
    tahfidzRaw('2026-10-01', 78, 1, 114, 6);
    tahfidzRawExam($this->halaqah, $this->student, ['kind' => 'tasmi_5_juz', 'juz_from' => 26, 'juz_to' => 30, 'exam_on' => '2026-10-10']);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([
        26 => ['lulus_ujian', '2026-10-10'], 27 => ['lulus_ujian', '2026-10-10'], 28 => ['lulus_ujian', '2026-10-10'],
        29 => ['lulus_ujian', '2026-10-10'], 30 => ['lulus_ujian', '2026-10-01'],
    ]);
});

it('AC-8: a failed exam does not change the progress', function () {
    tahfidzRawExam($this->halaqah, $this->student, ['result' => 'ulang', 'score' => 40]);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($this->student))->toBe([]);
});

it('AC-6: refreshing one student never touches another', function () {
    $other = tahfidzStudent($this->f);
    tahfidzMember($this->halaqah, $other);
    tahfidzRawRecord($this->halaqah, $other);
    tahfidzRaw('2026-10-01', 78, 1, 78, 10);

    tahfidzRefresh($this->student);

    expect(tahfidzProgress($other))->toBe([])->and(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);
});

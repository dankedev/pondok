<?php

use App\Actions\Tahfidz\DeleteTahfidzExam;
use App\Models\Halaqah;
use App\Models\Staff;
use App\Models\TahfidzExam;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->f = tahfidzBoot();
    $this->admin = tahfidzAdmin();
    [$this->halaqah, $this->student] = tahfidzHalaqahWithMember($this->f);
});

it('AC-8: a passed exam marks every juz of the range lulus_ujian even without setoran', function (string $kind, int $from, int $to) {
    $exam = tahfidzExam($this->admin, $this->halaqah, $this->student, ['kind' => $kind, 'juz_from' => $from, 'juz_to' => $to, 'certificate_no' => 'SYH-001']);

    $expected = [];
    foreach (range($from, $to) as $juz) {
        $expected[$juz] = ['lulus_ujian', '2026-10-14'];
    }

    expect($exam->certificate_no)->toBe('SYH-001')
        ->and(tahfidzProgress($this->student))->toBe($expected);
})->with([
    'kenaikan juz' => ['kenaikan_juz', 7, 7],
    'tasmi 5 juz' => ['tasmi_5_juz', 26, 30],
    'khataman' => ['khataman', 1, 30],
]);

it('AC-8: a failed exam is stored but changes nothing', function () {
    $exam = tahfidzExam($this->admin, $this->halaqah, $this->student, ['result' => 'ulang', 'score' => 40]);

    expect($exam->exists)->toBeTrue()
        ->and(tahfidzProgress($this->student))->toBe([]);
});

it('AC-8: rejects an invalid exam', function (array $overrides, array $fields) {
    tahfidzRejected(fn () => tahfidzExam($this->admin, $this->halaqah, $this->student, $overrides), $fields);

    expect(TahfidzExam::query()->count())->toBe(0);
})->with([
    'kenaikan juz over two juz' => [['juz_from' => 5, 'juz_to' => 6], ['juz_to']],
    'tasmi 5 juz over four juz' => [['kind' => 'tasmi_5_juz', 'juz_from' => 1, 'juz_to' => 4], ['juz_to']],
    'tasmi 5 juz over six juz' => [['kind' => 'tasmi_5_juz', 'juz_from' => 1, 'juz_to' => 6], ['juz_to']],
    'khataman not from juz 1' => [['kind' => 'khataman', 'juz_from' => 2, 'juz_to' => 30], ['juz_from', 'juz_to']],
    'khataman not to juz 30' => [['kind' => 'khataman', 'juz_from' => 1, 'juz_to' => 29], ['juz_to']],
    'juz zero' => [['juz_from' => 0, 'juz_to' => 0], ['juz_from']],
    'juz 31' => [['juz_from' => 31, 'juz_to' => 31], ['juz_from', 'juz_to']],
    'score above 100' => [['score' => 101], ['score']],
    'negative score' => [['score' => -1], ['score']],
    'future exam date' => [['exam_on' => '2026-10-16'], ['exam_on']],
    'certificate on a failed exam' => [['result' => 'ulang', 'certificate_no' => 'SYH-009'], ['certificate_no']],
]);

it('AC-8: rejects a duplicate certificate number but allows many exams without one', function () {
    tahfidzExam($this->admin, $this->halaqah, $this->student, ['certificate_no' => 'SYH-001']);
    tahfidzExam($this->admin, $this->halaqah, $this->student, ['juz_from' => 29, 'juz_to' => 29]);
    tahfidzExam($this->admin, $this->halaqah, $this->student, ['juz_from' => 28, 'juz_to' => 28]);

    tahfidzRejected(fn () => tahfidzExam($this->admin, $this->halaqah, $this->student, ['juz_from' => 27, 'juz_to' => 27, 'certificate_no' => 'SYH-001']), ['certificate_no']);

    expect(TahfidzExam::query()->count())->toBe(3);
});

it('AC-8: rejects a student who is not a member of the halaqah on the exam date, and an inactive halaqah or examiner', function (Closure $arrange, array $fields) {
    [$halaqah, $overrides] = $arrange($this);

    tahfidzRejected(fn () => tahfidzExam($this->admin, $halaqah, $this->student, $overrides), $fields);

    expect(TahfidzExam::query()->count())->toBe(0);
})->with([
    'before joined' => [fn ($t) => [$t->halaqah, ['exam_on' => '2026-06-30']], ['exam_on', 'student_id']],
    'other halaqah' => [fn ($t) => [tahfidzHalaqah($t->f), []], ['student_id', 'halaqah_id']],
    'inactive halaqah' => [function ($t) {
        Halaqah::query()->whereKey($t->halaqah->id)->update(['is_active' => false]);

        return [$t->halaqah->fresh(), []];
    }, ['halaqah_id']],
    'inactive examiner' => [function ($t) {
        Staff::query()->whereKey($t->halaqah->teacher_staff_id)->update(['is_active' => false]);

        return [$t->halaqah, []];
    }, ['examiner_staff_id']],
]);

it('AC-8: deleting a passed exam restores the status from the setoran', function () {
    tahfidzRawRecord($this->halaqah, $this->student, ['from_surah_id' => 78, 'from_ayah' => 1, 'to_surah_id' => 78, 'to_ayah' => 10]);
    $exam = tahfidzExam($this->admin, $this->halaqah, $this->student);

    expect(tahfidzProgress($this->student))->toBe([30 => ['lulus_ujian', '2026-10-14']]);

    app(DeleteTahfidzExam::class)->handle($this->admin, $exam);

    expect(TahfidzExam::query()->count())->toBe(0)
        ->and(tahfidzProgress($this->student))->toBe([30 => ['proses', null]]);
});

it('AC-8: lets a teacher record an exam for their own halaqah but not delete it', function () {
    $ustadz = tahfidzUstadz();
    [$halaqah, $student] = tahfidzHalaqahWithMember($this->f, $ustadz->staff);

    $exam = tahfidzExam($ustadz, $halaqah, $student);

    expect($exam->exists)->toBeTrue()
        ->and(fn () => app(DeleteTahfidzExam::class)->handle($ustadz, $exam))->toThrow(AuthorizationException::class)
        ->and(TahfidzExam::query()->count())->toBe(1);
});

it('AC-8: refuses to record an exam without create:tahfidz-exam', function () {
    expect(fn () => tahfidzExam(asramaUser('bendahara'), $this->halaqah, $this->student))->toThrow(AuthorizationException::class)
        ->and(TahfidzExam::query()->count())->toBe(0);
});

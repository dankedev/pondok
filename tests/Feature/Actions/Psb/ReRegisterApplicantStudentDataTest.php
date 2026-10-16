<?php

use App\Models\Cohort;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

it('AC-12: finds or creates the cohort', function () {
    expect(Cohort::query()->count())->toBe(0);

    $first = daftarUlangRun(daftarUlangApplicant($this->f))['student'];
    $cohort = Cohort::query()->where('unit_id', $this->f->sltp->id)->where('entry_year', 2027)->sole();

    expect($cohort->name)->toBe('Angkatan 2027')
        ->and($first->cohort_id)->toBe($cohort->id)
        ->and(Cohort::query()->count())->toBe(1);

    $second = daftarUlangRun(daftarUlangApplicant($this->f))['student'];

    expect($second->cohort_id)->toBe($cohort->id)->and(Cohort::query()->count())->toBe(1);
});

it('AC-12: reuses an existing cohort without renaming it', function () {
    $existing = Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2027, 'name' => 'Angkatan Perintis']);

    $student = daftarUlangRun(daftarUlangApplicant($this->f))['student'];

    expect($student->cohort_id)->toBe($existing->id)
        ->and(Cohort::query()->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Angkatan Perintis');
});

it('AC-16: rejects a taken nisn', function (bool $trashed) {
    $other = Student::factory()->create(['nisn' => '0123456789']);

    if ($trashed) {
        $other->delete();
    }

    $applicant = daftarUlangApplicant($this->f, ['nisn' => '0123456789']);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'nisn_taken', 'nisn');

    expect(daftarUlangSnapshot())->toBe($before);
})->with(['active student' => [false], 'deleted student' => [true]]);

it('AC-16: converts applicants without a nisn into students without a nisn', function () {
    $first = daftarUlangRun(daftarUlangApplicant($this->f, ['nisn' => null]))['student'];
    $second = daftarUlangRun(daftarUlangApplicant($this->f, ['nisn' => null]))['student'];

    expect($first->nisn)->toBeNull()->and($second->nisn)->toBeNull()->and($first->id)->not->toBe($second->id);
});

it('AC-17: sets the entry date to next month or the school year start', function (string $now, ?string $input, ?string $expected, ?string $reason) {
    $applicant = daftarUlangApplicant($this->f);
    $this->travelTo(CarbonImmutable::parse($now, 'Asia/Jakarta'));
    $before = daftarUlangSnapshot();
    $data = $input === null ? [] : ['entered_on' => $input];

    if ($reason !== null) {
        daftarUlangAssertRejected(fn () => daftarUlangRun($applicant, $data), $reason, 'entered_on');

        expect(daftarUlangSnapshot())->toBe($before);

        return;
    }

    expect(daftarUlangRun($applicant, $data)['student']->fresh()->entered_on->toDateString())->toBe($expected);
})->with([
    'default before the school year' => ['2026-10-15 10:00', null, '2027-07-01', null],
    'default after the school year started' => ['2027-08-15 10:00', null, '2027-09-01', null],
    'default on the last night of the month' => ['2026-10-31 23:30', null, '2027-07-01', null],
    'default on the last night of a school year month' => ['2027-08-31 23:30', null, '2027-09-01', null],
    'first day of next month' => ['2026-10-15 10:00', '2026-11-01', '2026-11-01', null],
    'a later month' => ['2026-10-15 10:00', '2027-02-01', '2027-02-01', null],
    'last day of this month' => ['2026-10-15 10:00', '2026-10-31', null, 'entered_on_too_early'],
    'today' => ['2026-10-15 10:00', '2026-10-15', null, 'entered_on_too_early'],
    'invalid month' => ['2026-10-15 10:00', '2026-13-01', null, 'date_invalid'],
    'wrong format' => ['2026-10-15 10:00', '01-11-2026', null, 'date_invalid'],
    'end of month, still this month' => ['2026-10-31 23:30', '2026-10-31', null, 'entered_on_too_early'],
    'end of month, next month' => ['2026-10-31 23:30', '2026-11-01', '2026-11-01', null],
]);

it('AC-17: names the earliest date in the error message', function () {
    $applicant = daftarUlangApplicant($this->f);

    try {
        daftarUlangRun($applicant, ['entered_on' => '2026-10-31']);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors()['entered_on'])->implode(' '))->toContain('1 Nov 2026');
    }
});

it('AC-17: leaves the student active even when the entry date is in the future', function () {
    $student = daftarUlangRun(daftarUlangApplicant($this->f))['student']->fresh();

    expect($student->status)->toBe('aktif')->and($student->entered_on->toDateString())->toBe('2027-07-01');
});

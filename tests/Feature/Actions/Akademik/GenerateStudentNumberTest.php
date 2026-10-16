<?php

use App\Actions\Akademik\GenerateStudentNumber;
use App\Models\Cohort;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

function daftarUlangGenerateNumber(object $f, int $year = 2027): string
{
    return app(GenerateStudentNumber::class)->handle($f->sltp, $year);
}

function daftarUlangExistingStudent(object $f, string $nis, bool $trashed = false, ?int $year = 2025): Student
{
    $cohort = Cohort::query()->firstOrCreate(['unit_id' => $f->sltp->id, 'entry_year' => $year], ['name' => "Angkatan {$year}"]);
    $student = Student::factory()->forCohort($cohort)->create(['nis' => $nis]);

    if ($trashed) {
        $student->delete();
    }

    return $student;
}

it('AC-13: numbers students by entry year', function () {
    expect(GenerateStudentNumber::DEFAULT_FORMAT)->toBe('{YYYY}{NNNN}')
        ->and(GenerateStudentNumber::SETTING_KEY)->toBe('students.nis_format')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f))['student']->nis)->toBe('20270001')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f))['student']->nis)->toBe('20270002');
});

it('AC-13: continues after the highest number, counts deleted students, and ignores other patterns', function () {
    daftarUlangExistingStudent($this->f, '20270005', trashed: true);
    daftarUlangExistingStudent($this->f, '2027000A');
    daftarUlangExistingStudent($this->f, '202700012');

    expect(daftarUlangGenerateNumber($this->f))->toBe('20270006')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f))['student']->nis)->toBe('20270006')
        ->and(daftarUlangGenerateNumber($this->f, 2028))->toBe('20280001');
});

it('AC-14: follows the configured format', function () {
    Setting::set('students.nis_format', '{UNIT}-{YY}-{NNN}');

    expect(daftarUlangGenerateNumber($this->f))->toBe('SLTP-27-001');

    daftarUlangExistingStudent($this->f, 'SLTP-27-007');

    expect(daftarUlangGenerateNumber($this->f))->toBe('SLTP-27-008')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f))['student']->nis)->toBe('SLTP-27-008');
});

it('AC-14: falls back to the default format and warns for an invalid format', function (string $format) {
    Setting::set('students.nis_format', $format);
    Log::spy();

    expect(GenerateStudentNumber::isValidFormat($format))->toBeFalse()
        ->and(daftarUlangGenerateNumber($this->f))->toBe('20270001');

    Log::shouldHaveReceived('warning')->atLeast()->once();
})->with([
    'no sequence' => ['{YYYY}'],
    'sequence not last' => ['{NNNN}{YYYY}'],
    'sequence too short' => ['{YYYY}{N}'],
    'unknown token' => ['{FOO}{NNNN}'],
    'two sequences' => ['{YYYY}{NNNN}{NNNN}'],
    'space' => ['{YYYY} {NNNN}'],
    'too long' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ{YYYY}{NNNNNN}'],
]);

it('AC-14: validates formats statically', function (string $format, bool $valid) {
    expect(GenerateStudentNumber::isValidFormat($format))->toBe($valid);
})->with([
    'default' => ['{YYYY}{NNNN}', true],
    'unit and short year' => ['{UNIT}-{YY}-{NNN}', true],
    'slash' => ['{YYYY}/{NNNNNN}', true],
    'literal prefix' => ['PK.{YYYY}.{NNNN}', true],
    'two N only' => ['{YYYY}{NN}', false],
    'seven N' => ['{YYYY}{NNNNNNN}', false],
    'underscore' => ['{YYYY}_{NNNN}', false],
]);

it('AC-14: refuses to exceed the width of the sequence', function () {
    Setting::set('students.nis_format', '{UNIT}{NNN}');
    daftarUlangExistingStudent($this->f, 'SLTP999');
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangGenerateNumber($this->f), 'nis_sequence_exhausted', 'nis', 'akademik');
    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'nis_sequence_exhausted', 'nis', 'akademik');

    expect(daftarUlangSnapshot())->toBe($before);
});

it('AC-15: accepts a manual number', function () {
    $student = daftarUlangRun(daftarUlangApplicant($this->f), ['nis' => ' 2027-SLTP-01 '])['student'];

    expect($student->nis)->toBe('2027-SLTP-01')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f), ['nis' => str_repeat('A', 30)])['student']->nis)->toBe(str_repeat('A', 30))
        ->and(daftarUlangRun(daftarUlangApplicant($this->f), ['nis' => ''])['student']->nis)->toBe('20270001')
        ->and(daftarUlangRun(daftarUlangApplicant($this->f), ['nis' => null])['student']->nis)->toBe('20270002');
});

it('AC-15: rejects a manual number that is taken, including by deleted students', function (bool $trashed) {
    daftarUlangExistingStudent($this->f, '2027-SLTP-01', trashed: $trashed);
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant, ['nis' => '2027-SLTP-01']), 'nis_taken', 'nis');

    expect(daftarUlangSnapshot())->toBe($before);
})->with(['active' => [false], 'deleted' => [true]]);

it('AC-15: rejects a malformed manual number', function (string $nis) {
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant, ['nis' => $nis]), 'nis_invalid', 'nis');

    expect(daftarUlangSnapshot())->toBe($before);
})->with([
    'space inside' => ['20 27'],
    'too long' => [str_repeat('1', 31)],
    'underscore' => ['2027_01'],
    'symbols' => ['  ??  '],
]);

it('AC-15: limits the address to 1000 characters after trimming', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant, ['address' => str_repeat('a', 1001)]), 'address_too_long', 'address');

    expect(daftarUlangSnapshot())->toBe($before);

    $student = daftarUlangRun($applicant, ['address' => '  '.str_repeat('a', 1000).'  '])['student']->fresh();

    expect($student->address)->toBe(str_repeat('a', 1000));
});

it('AC-15: stores a blank address as null', function () {
    expect(daftarUlangRun(daftarUlangApplicant($this->f), ['address' => '   '])['student']->fresh()->address)->toBeNull();
});

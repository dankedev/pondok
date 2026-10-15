<?php

use App\Actions\Dokumen\CheckDocumentCompleteness;
use App\Models\Document;
use App\Models\Student;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = dokumenFixture();
});

/**
 * Hasil ringkas `alasan per kode jenis`, urutan sesuai hasil Action.
 *
 * @return array<string, string>
 */
function dokumenCompleteness(Student $student, ?CarbonImmutable $today = null): array
{
    return collect(app(CheckDocumentCompleteness::class)->handle($student, $today))
        ->mapWithKeys(fn (array $item): array => [$item['document_type']->code => $item['reason']])
        ->all();
}

it('AC-24: lists missing required documents', function () {
    $items = app(CheckDocumentCompleteness::class)->handle($this->f->s1);

    expect($items)->toHaveCount(2)
        ->and($items[0]['document_type']->code)->toBe('AKTA')
        ->and($items[0]['document_type']->name)->toBe('Akta kelahiran')
        ->and($items[0]['reason'])->toBe('missing')
        ->and($items[1]['document_type']->code)->toBe('KK')
        ->and($items[1]['reason'])->toBe('missing');
});

it('AC-25: explains why a type is incomplete', function (string $case, array $expected) {
    $f = $this->f;
    $s1 = $f->s1;
    dokumenValid($s1, 'AKTA');

    switch ($case) {
        case 'pending':
            dokumenCreate($s1, 'KK');
            break;
        case 'rejected':
            dokumenCreate($s1, 'KK', ['status' => 'ditolak']);
            break;
        case 'valid':
            dokumenValid($s1, 'KK');
            break;
        case 'replaced valid by pending':
            $old = dokumenValid($s1, 'KK');
            $new = dokumenCreate($s1, 'KK');
            $old->forceFill(['replaced_by_id' => $new->id])->save();
            break;
        case 'rejected current beside a valid current':
            dokumenCreate($s1, 'KK', ['status' => 'ditolak']);
            dokumenValid($s1, 'KK');
            break;
        case 'valid replaced by rejected':
            $old = dokumenValid($s1, 'KK');
            $new = dokumenCreate($s1, 'KK', ['status' => 'ditolak']);
            $old->forceFill(['replaced_by_id' => $new->id])->save();
            break;
        case 'expired valid':
        case 'expires today':
        case 'pending beats expired':
        case 'expired beats rejected':
            dokumenValid($s1, 'KK');
            $kip = dokumenRequiredExpiringType();
            match ($case) {
                'expired valid' => dokumenValid($s1, $kip, ['expires_on' => '2026-10-14']),
                'expires today' => dokumenValid($s1, $kip, ['expires_on' => '2026-10-15']),
                'pending beats expired' => [dokumenValid($s1, $kip, ['expires_on' => '2026-10-14']), dokumenCreate($s1, $kip)],
                'expired beats rejected' => [dokumenValid($s1, $kip, ['expires_on' => '2026-10-14']), dokumenCreate($s1, $kip, ['status' => 'ditolak'])],
            };
            break;
    }

    expect(dokumenCompleteness($s1))->toBe($expected);
})->with([
    'pending' => ['pending', ['KK' => 'pending']],
    'rejected' => ['rejected', ['KK' => 'rejected']],
    'valid' => ['valid', []],
    'replaced valid by pending' => ['replaced valid by pending', ['KK' => 'pending']],
    'rejected current beside a valid current' => ['rejected current beside a valid current', []],
    'valid replaced by rejected' => ['valid replaced by rejected', ['KK' => 'rejected']],
    'expired valid' => ['expired valid', ['KIP' => 'expired']],
    'expires today' => ['expires today', []],
    'pending beats expired' => ['pending beats expired', ['KIP' => 'pending']],
    'expired beats rejected' => ['expired beats rejected', ['KIP' => 'expired']],
]);

it('AC-25: treats a document that expires tomorrow as complete today and expired the day after', function () {
    dokumenValid($this->f->s1, 'KK');
    dokumenValid($this->f->s1, 'AKTA');
    $kip = dokumenRequiredExpiringType();
    dokumenValid($this->f->s1, $kip, ['expires_on' => '2026-10-16']);

    expect(dokumenCompleteness($this->f->s1))->toBe([])
        ->and(dokumenCompleteness($this->f->s1, CarbonImmutable::parse('2026-10-16')))->toBe([])
        ->and(dokumenCompleteness($this->f->s1, CarbonImmutable::parse('2026-10-17')))->toBe(['KIP' => 'expired']);
});

it('AC-26: ignores optional, inactive and foreign documents', function () {
    $f = $this->f;

    // Jenis tidak wajib (RAPOR, IJAZAH) tidak pernah masuk daftar.
    expect(array_keys(dokumenCompleteness($f->s1)))->toBe(['AKTA', 'KK']);

    // Dokumen santri lain dan dokumen wali dengan jenis yang sama tidak dihitung untuk S1.
    dokumenValid($f->s2, 'KK');
    dokumenValid($f->s2, 'AKTA');
    dokumenValid($f->g1, 'KK');
    dokumenValid($f->t1, 'AKTA');

    expect(dokumenCompleteness($f->s1))->toBe(['AKTA' => 'missing', 'KK' => 'missing'])
        ->and(dokumenCompleteness($f->s2))->toBe([]);

    // Jenis wajib yang dinonaktifkan tidak dihitung.
    $f->types['KK']->update(['is_active' => false]);

    expect(dokumenCompleteness($f->s1))->toBe(['AKTA' => 'missing']);

    $f->types['AKTA']->update(['is_active' => false]);

    expect(app(CheckDocumentCompleteness::class)->handle($f->s1))->toBe([]);
});

it('AC-26: counts a student whose documents are hidden by the unit scope of the caller', function () {
    dokumenValid($this->f->s1, 'KK');
    dokumenValid($this->f->s1, 'AKTA');
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    expect(dokumenCompleteness($this->f->s1))->toBe([]);
});

/**
 * Empat santri SD: L lengkap, P berkas menunggu, M tanpa berkas, E berkas kedaluwarsa. Jenis KIP (wajib, berlaku
 * sementara) ikut diwajibkan; hanya L dan E yang punya KIP valid, E sudah lewat masa berlaku.
 *
 * @return array{L: Student, P: Student, M: Student, E: Student}
 */
function dokumenCompletenessStudents(object $f): array
{
    $kip = dokumenRequiredExpiringType();
    $make = fn (string $name): Student => Student::factory()->forCohort($f->cohortSd)->create(['name' => $name]);
    [$l, $p, $m, $e] = [$make('L'), $make('P'), $make('M'), $make('E')];

    foreach ([$l, $p, $e] as $student) {
        dokumenValid($student, 'AKTA');
    }
    dokumenValid($l, 'KK');
    dokumenValid($l, $kip, ['expires_on' => '2027-01-01']);
    dokumenCreate($p, 'KK');
    dokumenValid($p, $kip, ['expires_on' => '2027-01-01']);
    dokumenValid($e, 'KK');
    dokumenValid($e, $kip, ['expires_on' => '2026-10-14']);

    return ['L' => $l, 'P' => $p, 'M' => $m, 'E' => $e];
}

it('AC-27: matches the student completeness scopes', function () {
    $students = dokumenCompletenessStudents($this->f);
    $ids = collect($students)->map->id->values()->all();
    $sorted = fn (array $values): array => collect($values)->sort()->values()->all();

    expect($sorted(Student::query()->withIncompleteDocuments()->whereIn('id', $ids)->pluck('id')->all()))
        ->toBe($sorted([$students['P']->id, $students['M']->id, $students['E']->id]))
        ->and($sorted(Student::query()->withCompleteDocuments()->whereIn('id', $ids)->pluck('id')->all()))
        ->toBe([$students['L']->id])
        ->and($sorted(Student::query()->withIncompleteDocuments(CarbonImmutable::parse('2026-10-15'))->whereIn('id', $ids)->pluck('id')->all()))
        ->toBe($sorted([$students['P']->id, $students['M']->id, $students['E']->id]));
});

it('AC-27: lists a student as incomplete if and only if the action reports a gap', function () {
    $students = dokumenCompletenessStudents($this->f);
    $all = [...$students, 's1' => $this->f->s1, 's2' => $this->f->s2];
    $incomplete = Student::query()->withIncompleteDocuments()->pluck('id')->all();
    $complete = Student::query()->withCompleteDocuments()->pluck('id')->all();

    foreach ($all as $name => $student) {
        $gap = app(CheckDocumentCompleteness::class)->handle($student) !== [];

        expect(in_array($student->id, $incomplete, true))->toBe($gap, "incomplete {$name}")
            ->and(in_array($student->id, $complete, true))->toBe(! $gap, "complete {$name}");
    }
});

it('AC-27: treats every student as complete when no required type is active', function () {
    $this->f->types['KK']->update(['is_active' => false]);
    $this->f->types['AKTA']->update(['is_active' => false]);

    expect(Student::query()->withIncompleteDocuments()->count())->toBe(0)
        ->and(Student::query()->withCompleteDocuments()->count())->toBe(2);
});

it('AC-27: evaluates the scopes at the given date', function () {
    $students = dokumenCompletenessStudents($this->f);
    $ids = [$students['L']->id];

    expect(Student::query()->withCompleteDocuments(CarbonImmutable::parse('2026-10-15'))->whereIn('id', $ids)->count())->toBe(1)
        ->and(Student::query()->withIncompleteDocuments(CarbonImmutable::parse('2027-01-02'))->whereIn('id', $ids)->count())->toBe(1);
});

it('AC-27: does not run a query per document', function () {
    $s1 = $this->f->s1;
    $kip = dokumenRequiredExpiringType();
    dokumenValid($s1, 'KK');
    dokumenCreate($s1, 'AKTA');
    dokumenCreate($s1, 'AKTA', ['status' => 'ditolak']);
    dokumenValid($s1, $kip, ['expires_on' => '2026-10-14']);
    dokumenCreate($s1, 'RAPOR');

    expect(Document::query()->where('documentable_id', $s1->id)->count())->toBe(5)
        ->and(dokumenQueryCount(fn () => app(CheckDocumentCompleteness::class)->handle($s1)))->toBeLessThanOrEqual(3);
});

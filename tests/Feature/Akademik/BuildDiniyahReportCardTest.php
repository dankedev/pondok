<?php

use App\Actions\Akademik\BuildDiniyahReportCard;
use App\Models\Classroom;
use App\Models\DiniyahSubject;
use App\Models\Enrollment;

/*
| Kontrak `BuildDiniyahReportCard::handle(Student, AcademicYear, int $semester): array` dengan kunci
| `student`, `year`, `semester`, `classroom`, `homeroom` (Staff|null), `rows` (urut `sort_order`; tiap baris
| `subject` nama, `score` int|null, `predicate` DiniyahPredicate|null, `passing_score` int|null,
| `below_passing` bool), `average` (float|null), `note` (string|null).
| Jenjang mapel diambil dari `classrooms.unit_id` pada enrollment tahun itu, bukan `students.unit_id` sekarang.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

it('AC-8: returns identity, classroom, homeroom, rows by sort order with predicate, average and note', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 90);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 81);
    diniyahGrade($f->s1, $f->year, 1, $f->nahwu, 70);
    diniyahNote($f->s1, $f->year, 1, 'Rajin berjamaah');

    $card = diniyahCard($f->s1, $f->year, 1);

    expect($card['student']->is($f->s1))->toBeTrue()
        ->and($card['year']->is($f->year))->toBeTrue()
        ->and($card['semester'])->toBe(1)
        ->and($card['classroom']->is($f->c7a))->toBeTrue()
        ->and($card['homeroom']->is($f->homeroomStaff))->toBeTrue()
        ->and(array_slice(diniyahRowNames($card), 0, 3))->toBe(['Fiqih', 'Aqidah', 'Nahwu'])
        ->and(diniyahRow($card, 'Aqidah'))->toMatchArray(['score' => 90, 'predicate' => 'mumtaz'])
        ->and(diniyahRow($card, 'Fiqih'))->toMatchArray(['score' => 81, 'predicate' => 'jayyid_jiddan'])
        ->and(diniyahRow($card, 'Nahwu'))->toMatchArray(['score' => 70, 'predicate' => 'jayyid'])
        ->and(round((float) $card['average'], 1))->toBe(80.3)
        ->and($card['note'])->toBe('Rajin berjamaah')
        ->and(array_keys($card))->not->toContain('rank', 'ranking', 'rangking');
});

it('AC-8: has no average (not zero) and no note when nothing is filled', function () {
    $f = $this->f;

    $card = diniyahCard($f->s1, $f->year, 1);

    expect($card['average'])->toBeNull()
        ->and($card['note'])->toBeNull()
        ->and(collect($card['rows'])->every(fn (mixed $row): bool => data_get($row, 'score') === null))->toBeTrue();
});

it('AC-8: averages only the filled scores of the requested semester', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 100);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 50);
    diniyahGrade($f->s1, $f->year, 2, $f->aqidah, 10);

    expect((float) diniyahCard($f->s1, $f->year, 1)['average'])->toBe(75.0)
        ->and((float) diniyahCard($f->s1, $f->year, 2)['average'])->toBe(10.0)
        ->and(diniyahRow(diniyahCard($f->s1, $f->year, 2), 'Fiqih')['score'] ?? null)->toBeNull();
});

it('AC-8: still shows an inactive subject that already has a score, and hides one that has none', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->sharaf, 77);

    $card = diniyahCard($f->s1, $f->year, 1);

    expect(diniyahRow($card, 'Sharaf'))->toMatchArray(['score' => 77, 'predicate' => 'jayyid'])
        ->and(diniyahRow(diniyahCard($f->s2, $f->year, 1), 'Sharaf'))->toBeNull();
});

it('AC-8: uses the subjects of the classroom unit of that year after the student changes unit', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 88);
    $f->s1->forceFill(['unit_id' => $f->slta->id])->save();

    $card = diniyahCard($f->s1->fresh(), $f->year, 1);

    expect(diniyahRow($card, 'Aqidah'))->toMatchArray(['score' => 88])
        ->and(diniyahRow($card, 'Hadits'))->toBeNull()
        ->and($card['classroom']->is($f->c7a))->toBeTrue();
});

it('AC-12: builds the card of a past year with its own classroom after promotion and when that year is not active', function () {
    $f = $this->f;
    diniyahGrade($f->old, $f->prev, 2, $f->aqidah, 92);
    $c7c = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->sltp->id, 'grade' => 7, 'name' => '7C']);
    Enrollment::factory()->create(['student_id' => $f->old->id, 'classroom_id' => $c7c->id, 'academic_year_id' => $f->year->id]);

    $card = diniyahCard($f->old, $f->prev, 2);

    expect($f->prev->is_active)->toBeFalse()
        ->and($card['classroom']->is($f->cOld))->toBeTrue()
        ->and(diniyahRow($card, 'Aqidah'))->toMatchArray(['score' => 92, 'predicate' => 'mumtaz'])
        ->and(diniyahCard($f->old, $f->year, 2)['classroom']->is($c7c))->toBeTrue();
});

it('AC-8: is read-only and builds without a logged-in user', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 90);
    $before = diniyahSnapshot();

    app(BuildDiniyahReportCard::class)->handle($f->s1, $f->year, 1);

    expect(diniyahSnapshot())->toBe($before);
});

it('AC-3: marks only scores strictly below the passing score', function () {
    $subject = new DiniyahSubject(['passing_score' => 70]);
    $none = new DiniyahSubject;

    expect($subject->isBelowPassing(69))->toBeTrue('69 vs 70')
        ->and($subject->isBelowPassing(70))->toBeFalse('70 vs 70')
        ->and($subject->isBelowPassing(71))->toBeFalse('71 vs 70')
        ->and($subject->isBelowPassing(null))->toBeFalse('null vs 70')
        ->and($none->isBelowPassing(0))->toBeFalse('0 vs null');
});

it('AC-12: returns the passing score and the flag per row, also for an inactive subject with a score', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 70])->save();
    $f->fiqih->forceFill(['passing_score' => 70])->save();
    $f->sharaf->forceFill(['passing_score' => 80])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 65);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 70);
    diniyahGrade($f->s1, $f->year, 1, $f->nahwu, 10);
    diniyahGrade($f->s1, $f->year, 1, $f->sharaf, 79);

    $card = diniyahCard($f->s1, $f->year, 1);

    expect(diniyahRow($card, 'Aqidah'))->toMatchArray(['score' => 65, 'passing_score' => 70, 'below_passing' => true])
        ->and(diniyahRow($card, 'Fiqih'))->toMatchArray(['score' => 70, 'passing_score' => 70, 'below_passing' => false])
        ->and(diniyahRow($card, 'Nahwu'))->toMatchArray(['score' => 10, 'passing_score' => null, 'below_passing' => false])
        ->and(diniyahRow($card, 'Sharaf'))->toMatchArray(['score' => 79, 'passing_score' => 80, 'below_passing' => true])
        ->and(round((float) $card['average'], 1))->toBe(56.0)
        ->and(diniyahRow($card, 'Aqidah')['predicate'])->toBe('maqbul');
});

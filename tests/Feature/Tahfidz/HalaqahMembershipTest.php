<?php

use App\Actions\Tahfidz\AddHalaqahMember;
use App\Actions\Tahfidz\EndHalaqahMembership;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->f = tahfidzBoot();
    $this->admin = asramaUser('admin-sekolah');
});

function tahfidzAdd(User $actor, Halaqah $halaqah, Student $student, string $on = '2026-10-01'): HalaqahMember
{
    return app(AddHalaqahMember::class)->handle($actor, $halaqah, $student, CarbonImmutable::parse($on));
}

function tahfidzEnd(User $actor, HalaqahMember $member, string $on): HalaqahMember
{
    return app(EndHalaqahMembership::class)->handle($actor, $member, CarbonImmutable::parse($on));
}

it('AC-4: adds a student as one active member', function () {
    $halaqah = tahfidzHalaqah($this->f);
    $student = tahfidzStudent($this->f);

    $member = tahfidzAdd($this->admin, $halaqah, $student);

    expect($member->halaqah_id)->toBe($halaqah->id)
        ->and($member->student_id)->toBe($student->id)
        ->and($member->joined_on->toDateString())->toBe('2026-10-01')
        ->and($member->left_on)->toBeNull()
        ->and(HalaqahMember::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('AC-4: moving to another halaqah closes the old row and adds a new one', function () {
    $a = tahfidzHalaqah($this->f);
    $b = tahfidzHalaqah($this->f);
    $student = tahfidzStudent($this->f);
    $old = tahfidzAdd($this->admin, $a, $student, '2026-08-01');

    $new = tahfidzAdd($this->admin, $b, $student, '2026-10-01');

    expect($old->fresh()->left_on->toDateString())->toBe('2026-10-01')
        ->and($new->halaqah_id)->toBe($b->id)
        ->and($new->left_on)->toBeNull()
        ->and(HalaqahMember::query()->where('student_id', $student->id)->count())->toBe(2)
        ->and(HalaqahMember::query()->where('student_id', $student->id)->whereNull('left_on')->count())->toBe(1);
});

it('AC-4: adding to the same halaqah again returns the existing row without a new one', function () {
    $halaqah = tahfidzHalaqah($this->f);
    $student = tahfidzStudent($this->f);

    $first = tahfidzAdd($this->admin, $halaqah, $student, '2026-09-01');
    $second = tahfidzAdd($this->admin, $halaqah, $student, '2026-10-01');

    expect($second->id)->toBe($first->id)
        ->and(HalaqahMember::query()->count())->toBe(1)
        ->and($first->fresh()->joined_on->toDateString())->toBe('2026-09-01');
});

it('AC-4: accepts any level in a cross-level halaqah', function () {
    $halaqah = tahfidzHalaqah($this->f, null, ['unit_id' => null]);

    expect(tahfidzAdd($this->admin, $halaqah, tahfidzStudent($this->f, 'L', $this->f->slta))->exists)->toBeTrue();
});

it('AC-4: rejects the member', function (Closure $arrange, array|string $field) {
    [$halaqah, $student, $on] = $arrange($this);
    $before = HalaqahMember::query()->count();

    tahfidzRejected(fn () => tahfidzAdd($this->admin, $halaqah, $student, $on), $field);

    expect(HalaqahMember::query()->count())->toBe($before)
        ->and(HalaqahMember::query()->where('student_id', $student->id)->whereNotNull('left_on')->count())->toBe(0);
})->with([
    'other gender' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f, 'P'), '2026-10-01'], ['student_id']],
    'other level' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f, 'L', $t->f->slta), '2026-10-01'], ['student_id']],
    'inactive student' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f, 'L', null, ['status' => 'lulus']), '2026-10-01'], ['student_id']],
    'inactive halaqah' => [fn ($t) => [tahfidzHalaqah($t->f, null, ['is_active' => false]), tahfidzStudent($t->f), '2026-10-01'], ['halaqah_id', 'student_id']],
    'before the academic year' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f), '2026-06-30'], ['joined_on']],
    'after the academic year' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f), '2027-07-01'], ['joined_on']],
    'in the future' => [fn ($t) => [tahfidzHalaqah($t->f), tahfidzStudent($t->f), '2026-10-16'], ['joined_on']],
    'before the old membership' => [function ($t) {
        $student = tahfidzStudent($t->f);
        tahfidzMember(tahfidzHalaqah($t->f), $student, '2026-10-10');

        return [tahfidzHalaqah($t->f), $student, '2026-10-05'];
    }, ['joined_on']],
]);

it('AC-4: ends a membership, also on the day it started', function () {
    $halaqah = tahfidzHalaqah($this->f);
    $member = tahfidzMember($halaqah, tahfidzStudent($this->f), '2026-10-05');

    $ended = tahfidzEnd($this->admin, $member, '2026-10-05');

    expect($ended->left_on->toDateString())->toBe('2026-10-05')
        ->and(HalaqahMember::query()->whereNull('left_on')->count())->toBe(0);
});

it('AC-4: rejects ending a membership before it started, in the future, or twice', function (string $on, bool $alreadyEnded) {
    $member = tahfidzMember(tahfidzHalaqah($this->f), tahfidzStudent($this->f), '2026-10-05', $alreadyEnded ? '2026-10-08' : null);

    tahfidzRejected(fn () => tahfidzEnd($this->admin, $member, $on), ['left_on']);

    expect($member->fresh()->left_on?->toDateString())->toBe($alreadyEnded ? '2026-10-08' : null);
})->with([
    'before joined' => ['2026-10-04', false],
    'in the future' => ['2026-10-16', false],
    'already ended' => ['2026-10-09', true],
]);

it('AC-4, AC-10: only update:halaqah holders manage members, not even the teacher of that halaqah', function () {
    $ustadz = tahfidzUstadz();
    $halaqah = tahfidzHalaqah($this->f, $ustadz->staff);
    $member = tahfidzMember($halaqah, tahfidzStudent($this->f), '2026-10-05');
    $student = tahfidzStudent($this->f);

    expect(fn () => tahfidzAdd($ustadz, $halaqah, $student))->toThrow(AuthorizationException::class)
        ->and(fn () => tahfidzEnd($ustadz, $member, '2026-10-10'))->toThrow(AuthorizationException::class)
        ->and(fn () => tahfidzAdd(asramaUser('bendahara'), $halaqah, $student))->toThrow(AuthorizationException::class)
        ->and(HalaqahMember::query()->count())->toBe(1)
        ->and($member->fresh()->left_on)->toBeNull();
});

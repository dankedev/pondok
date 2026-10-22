<?php

use App\Actions\Tahfidz\ResolveTahfidzTarget;
use App\Filament\Resources\Halaqahs\Pages\EditHalaqah;
use App\Filament\Resources\Halaqahs\RelationManagers\MembersRelationManager;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Halaqah;
use App\Models\Student;
use App\Models\TahfidzTarget;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = tahfidzBoot();
});

function tahfidzTarget(object $f, ?int $grade, float $juz, ?Unit $unit = null, ?AcademicYear $year = null): TahfidzTarget
{
    return TahfidzTarget::query()->forceCreate([
        'academic_year_id' => ($year ?? $f->year)->id,
        'unit_id' => ($unit ?? $f->sltp)->id,
        'grade' => $grade,
        'target_juz' => $juz,
    ]);
}

function tahfidzEnrolled(object $f, Student $student, int $grade): void
{
    $classroom = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $student->unit_id, 'grade' => $grade]);
    Enrollment::factory()->create(['student_id' => $student->id, 'classroom_id' => $classroom->id]);
}

it('AC-9: resolves the target by grade, then all grades, then null', function () {
    $grade7 = tahfidzTarget($this->f, 7, 2.0);
    $allSltp = tahfidzTarget($this->f, null, 1.0);
    $allSlta = tahfidzTarget($this->f, null, 3.0, $this->f->slta);
    $otherYear = AcademicYear::factory()->create();
    tahfidzTarget($this->f, 8, 9.0, null, $otherYear);

    $a = tahfidzStudent($this->f);
    tahfidzEnrolled($this->f, $a, 7);
    $b = tahfidzStudent($this->f);
    tahfidzEnrolled($this->f, $b, 8);
    $c = tahfidzStudent($this->f, 'L', $this->f->slta);
    tahfidzEnrolled($this->f, $c, 10);
    $unenrolled = tahfidzStudent($this->f);

    $resolve = fn (Student $student): ?int => app(ResolveTahfidzTarget::class)->handle($student)?->id;

    expect($resolve($a))->toBe($grade7->id)
        ->and($resolve($b))->toBe($allSltp->id)
        ->and($resolve($c))->toBe($allSlta->id)
        ->and($resolve($unenrolled))->toBe($allSltp->id);

    TahfidzTarget::query()->whereKey([$allSltp->id])->delete();

    expect($resolve($b))->toBeNull()->and($resolve($a))->toBe($grade7->id);
});

it('AC-9: the target is unique per year, level and grade, including the all-grades row', function (?int $grade) {
    tahfidzTarget($this->f, $grade, 1.0);

    expect(fn () => tahfidzTarget($this->f, $grade, 2.0))->toThrow(QueryException::class)
        ->and(TahfidzTarget::query()->count())->toBe(1);
})->with(['all grades' => [null], 'grade 7' => [7]]);

it('AC-9: allows the same grade in another level or year and the all-grades row beside a grade row', function () {
    tahfidzTarget($this->f, 7, 1.0);
    tahfidzTarget($this->f, null, 1.0);
    tahfidzTarget($this->f, 7, 1.0, $this->f->slta);
    tahfidzTarget($this->f, 7, 1.0, null, AcademicYear::factory()->create());

    expect(TahfidzTarget::query()->count())->toBe(4);
});

function tahfidzMembersManager(Halaqah $halaqah)
{
    return Livewire::test(MembersRelationManager::class, ['ownerRecord' => $halaqah, 'pageClass' => EditHalaqah::class]);
}

it('AC-9: shows "Di bawah target" only for a member below an existing target', function () {
    $this->actingAs(asramaUser('admin-sekolah'));
    tahfidzTarget($this->f, null, 1.0);
    $halaqah = tahfidzHalaqah($this->f);
    $done = tahfidzStudent($this->f);
    tahfidzMember($halaqah, $done);
    tahfidzRawRecord($halaqah, $done, ['recorded_on' => '2026-10-01', 'from_surah_id' => 78, 'from_ayah' => 1, 'to_surah_id' => 114, 'to_ayah' => 6]);
    tahfidzRefresh($done);

    tahfidzMembersManager($halaqah)->assertDontSee('Di bawah target');

    $below = tahfidzStudent($this->f);
    tahfidzMember($halaqah, $below);

    tahfidzMembersManager($halaqah)->assertSee('Di bawah target');

    $noTarget = tahfidzHalaqah($this->f, null, ['unit_id' => $this->f->slta->id]);
    tahfidzMember($noTarget, tahfidzStudent($this->f, 'L', $this->f->slta));

    tahfidzMembersManager($noTarget)->assertDontSee('Di bawah target');
});

it('AC-9: the members table runs a constant number of queries for 1 and for 5 members', function () {
    $this->actingAs(asramaUser('admin-sekolah'));
    tahfidzTarget($this->f, null, 1.0);
    tahfidzTarget($this->f, 7, 2.0);

    $build = function (int $count): Halaqah {
        $halaqah = tahfidzHalaqah($this->f);
        foreach (range(1, $count) as $i) {
            $student = tahfidzStudent($this->f);
            tahfidzEnrolled($this->f, $student, 6 + ($i % 3));
            tahfidzMember($halaqah, $student);
            tahfidzRawRecord($halaqah, $student, ['recorded_on' => '2026-10-01', 'from_surah_id' => 78, 'from_ayah' => 1, 'to_surah_id' => 114, 'to_ayah' => 6]);
            tahfidzRefresh($student);
        }

        return $halaqah;
    };

    $one = $build(1);
    $five = $build(5);
    tahfidzMembersManager($one);

    $queriesOne = prestasiQueryCount(fn () => tahfidzMembersManager($one));
    $queriesFive = prestasiQueryCount(fn () => tahfidzMembersManager($five));

    expect($queriesFive)->toBe($queriesOne);
});

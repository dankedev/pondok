<?php

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Kontrak: `SaveDiniyahGrades::handle(User, Classroom, mixed $semester, array $scores, array $notes): int`
| dengan `$scores = [student_id => [subject_id => nilai|null]]` dan `$notes = [student_id => catatan|null]`.
| Roster = santri berstatus aktif yang punya enrollment di rombel itu. Tahun ajaran rombel tidak harus aktif.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

it('AC-3: saves scores and notes of one classroom semester with the recorder', function () {
    $f = $this->f;

    $saved = diniyahSave($f->homeroom, $f->c7a, 1, [
        $f->s1->id => [$f->aqidah->id => 90, $f->fiqih->id => 75],
        $f->s2->id => [$f->aqidah->id => 60],
    ], [$f->s1->id => 'Rajin dan santun']);

    expect($saved)->toBe(4)
        ->and(diniyahStored($f->s1))->toBe([$f->aqidah->id => 90, $f->fiqih->id => 75])
        ->and(diniyahStored($f->s2))->toBe([$f->aqidah->id => 60])
        ->and(DiniyahGrade::query()->pluck('recorded_by')->unique()->all())->toBe([$f->homeroom->id])
        ->and(DiniyahGrade::query()->pluck('academic_year_id')->unique()->all())->toBe([$f->year->id])
        ->and(DiniyahReportNote::query()->sole()->note)->toBe('Rajin dan santun')
        ->and(DiniyahReportNote::query()->sole()->recorded_by)->toBe($f->homeroom->id)
        ->and(DiniyahReportNote::query()->sole()->semester)->toBe(1);
});

it('AC-4: is idempotent, keeps timestamps for equal data and updates the same row on change', function () {
    $f = $this->f;
    $scores = [$f->s1->id => [$f->aqidah->id => 90, $f->fiqih->id => 75]];
    $notes = [$f->s1->id => 'Baik'];

    diniyahSave($f->homeroom, $f->c7a, 1, $scores, $notes);
    $before = diniyahSnapshot();

    $this->travel(2)->hours();
    diniyahSave($f->homeroom, $f->c7a, 1, $scores, $notes);

    expect(diniyahSnapshot())->toBe($before)
        ->and(DiniyahGrade::query()->count())->toBe(2)
        ->and(DiniyahReportNote::query()->count())->toBe(1);

    $rowId = DiniyahGrade::query()->where('diniyah_subject_id', $f->aqidah->id)->value('id');

    diniyahSave($f->admin, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 95]], [$f->s1->id => 'Sangat baik']);

    expect(DiniyahGrade::query()->count())->toBe(2)
        ->and(DiniyahGrade::query()->where('diniyah_subject_id', $f->aqidah->id)->value('id'))->toBe($rowId)
        ->and(diniyahStored($f->s1))->toBe([$f->aqidah->id => 95, $f->fiqih->id => 75])
        ->and(DiniyahReportNote::query()->sole()->note)->toBe('Sangat baik');
});

it('AC-5: clears a score and a note with an empty value and leaves other semesters alone', function () {
    $f = $this->f;
    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 80, $f->fiqih->id => 70, $f->nahwu->id => 60]], [$f->s1->id => 'Catatan satu']);
    diniyahSave($f->homeroom, $f->c7a, 2, [$f->s1->id => [$f->aqidah->id => 85]], [$f->s1->id => 'Catatan dua']);

    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => null, $f->fiqih->id => '', $f->nahwu->id => 60]], [$f->s1->id => null]);

    expect(diniyahStored($f->s1))->toBe([$f->nahwu->id => 60])
        ->and(diniyahStored($f->s1, 2))->toBe([$f->aqidah->id => 85])
        ->and(DiniyahReportNote::query()->where('semester', 1)->count())->toBe(0)
        ->and(DiniyahReportNote::query()->where('semester', 2)->sole()->note)->toBe('Catatan dua');

    diniyahSave($f->homeroom, $f->c7a, 2, [], [$f->s1->id => '']);

    expect(DiniyahReportNote::query()->count())->toBe(0);
});

it('AC-5: accepts the boundary scores 0 and 100 as real values, not as empty', function () {
    $f = $this->f;

    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 0, $f->fiqih->id => 100]]);

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 0, $f->fiqih->id => 100]);
});

it('AC-6: rejects the whole save for :case and stores nothing', function (string $case) {
    $f = $this->f;
    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 70]]);
    $before = diniyahSnapshot();

    $valid = [$f->s2->id => [$f->aqidah->id => 88]];
    [$semester, $scores, $notes] = match ($case) {
        'nilai -1' => [1, [$f->s1->id => [$f->aqidah->id => -1]], []],
        'nilai 101' => [1, [$f->s1->id => [$f->aqidah->id => 101]], []],
        'nilai desimal teks' => [1, [$f->s1->id => [$f->aqidah->id => '85.5']], []],
        'nilai desimal float' => [1, [$f->s1->id => [$f->aqidah->id => 85.5]], []],
        'nilai teks' => [1, [$f->s1->id => [$f->aqidah->id => 'abc']], []],
        'semester 3' => [3, [$f->s1->id => [$f->aqidah->id => 80]], []],
        'semester 0' => [0, [$f->s1->id => [$f->aqidah->id => 80]], []],
        'mapel nonaktif' => [1, [$f->s1->id => [$f->sharaf->id => 80]], []],
        'mapel jenjang lain' => [1, [$f->s1->id => [$f->hadits->id => 80]], []],
        'mapel tidak ada' => [1, [$f->s1->id => [999999 => 80]], []],
        'santri rombel lain' => [1, [$f->o7b->id => [$f->aqidah->id => 80]], []],
        'santri tahun lain' => [1, [$f->old->id => [$f->aqidah->id => 80]], []],
        'santri tidak aktif' => [1, [$f->grad->id => [$f->aqidah->id => 80]], []],
        'catatan santri rombel lain' => [1, [], [$f->o7b->id => 'Catatan']],
    };

    expect(fn () => diniyahSave($f->homeroom, $f->c7a, $semester, $valid + $scores, $notes))->toThrow(ValidationException::class)
        ->and(diniyahSnapshot())->toBe($before);
})->with([
    'nilai -1', 'nilai 101', 'nilai desimal teks', 'nilai desimal float', 'nilai teks', 'semester 3', 'semester 0',
    'mapel nonaktif', 'mapel jenjang lain', 'mapel tidak ada', 'santri rombel lain', 'santri tahun lain',
    'santri tidak aktif', 'catatan santri rombel lain',
]);

it('AC-6: also rejects an invalid cell that comes after a clearing of an existing score', function () {
    $f = $this->f;
    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 70]]);
    $before = diniyahSnapshot();

    expect(fn () => diniyahSave($f->homeroom, $f->c7a, 1, [
        $f->s1->id => [$f->aqidah->id => null],
        $f->s2->id => [$f->aqidah->id => 101],
    ]))->toThrow(ValidationException::class)
        ->and(diniyahSnapshot())->toBe($before);
});

it('AC-7: lets wali-kelas save their own classroom, admin-sekolah save in scope, in any academic year', function () {
    $f = $this->f;

    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 81]]);
    diniyahSave($f->admin, $f->c7b, 1, [$f->o7b->id => [$f->aqidah->id => 82]]);
    diniyahSave($f->adminSltp, $f->c7b, 1, [$f->o7b->id => [$f->fiqih->id => 83]]);
    diniyahSave($f->admin, $f->c10a, 1, [$f->o10->id => [$f->hadits->id => 84]]);
    diniyahSave($f->admin, $f->cOld, 2, [$f->old->id => [$f->aqidah->id => 85]]);

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 81])
        ->and(diniyahStored($f->o7b))->toBe([$f->aqidah->id => 82, $f->fiqih->id => 83])
        ->and(diniyahStored($f->o10))->toBe([$f->hadits->id => 84])
        ->and(diniyahStored($f->old, 2))->toBe([$f->aqidah->id => 85])
        ->and(DiniyahGrade::query()->where('student_id', $f->old->id)->value('academic_year_id'))->toBe($f->prev->id);
});

it('AC-7: refuses :account to save grades and stores nothing', function (string $account) {
    $f = $this->f;
    $user = property_exists($f, $account) ? $f->{$account} : asramaUser($account);
    $before = diniyahSnapshot();

    expect(fn () => diniyahSave($user, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 80]], [$f->s1->id => 'Catatan']))
        ->toThrow(AuthorizationException::class)
        ->and(diniyahSnapshot())->toBe($before);
})->with(['kes', 'kesSltp', 'idle', 'noStaff', 'musyrif', 'wali', 'bendahara']);

it('AC-7: refuses wali-kelas for another classroom and admin-sekolah outside their unit', function () {
    $f = $this->f;
    $before = diniyahSnapshot();

    expect(fn () => diniyahSave($f->homeroom, $f->c7b, 1, [$f->o7b->id => [$f->aqidah->id => 80]]))->toThrow(AuthorizationException::class)
        ->and(fn () => diniyahSave($f->homeroom, $f->c10a, 1, [$f->o10->id => [$f->hadits->id => 80]]))->toThrow(AuthorizationException::class)
        ->and(fn () => diniyahSave($f->adminSltp, $f->c10a, 1, [$f->o10->id => [$f->hadits->id => 80]]))->toThrow(AuthorizationException::class)
        ->and(diniyahSnapshot())->toBe($before);
});

it('AC-7: requires the input-diniyah-grades permission even for the homeroom teacher', function () {
    $f = $this->f;
    Role::findByName('wali-kelas')->revokePermissionTo('input-diniyah-grades');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $homeroom = User::query()->findOrFail($f->homeroom->id);

    expect(fn () => diniyahSave($homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 80]]))->toThrow(AuthorizationException::class)
        ->and(DiniyahGrade::query()->count())->toBe(0);
});

it('AC-12: keeps the grades of a past year readable after the student is promoted', function () {
    $f = $this->f;
    diniyahSave($f->homeroom, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 88]], [$f->s1->id => 'Naik kelas']);

    $next = AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);
    $c8a = Classroom::factory()->create(['academic_year_id' => $next->id, 'unit_id' => $f->sltp->id, 'grade' => 8, 'name' => '8A']);
    Enrollment::factory()->create(['student_id' => $f->s1->id, 'classroom_id' => $c8a->id, 'academic_year_id' => $next->id]);
    $f->year->forceFill(['is_active' => false])->save();
    $next->forceFill(['is_active' => true])->save();

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 88])
        ->and(DiniyahReportNote::query()->sole()->note)->toBe('Naik kelas');

    diniyahSave($f->admin, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => 89]]);

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 89]);
});

it('AC-13: creates the three tables and rejects duplicate keys in the database', function () {
    $f = $this->f;

    expect(Schema::hasColumns('diniyah_subjects', ['id', 'unit_id', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('diniyah_grades', ['id', 'student_id', 'academic_year_id', 'semester', 'diniyah_subject_id', 'score', 'recorded_by', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('diniyah_report_notes', ['id', 'student_id', 'academic_year_id', 'semester', 'note', 'recorded_by', 'created_at', 'updated_at']))->toBeTrue();

    $grade = ['student_id' => $f->s1->id, 'academic_year_id' => $f->year->id, 'semester' => 1, 'diniyah_subject_id' => $f->aqidah->id, 'score' => 80];
    $note = ['student_id' => $f->s1->id, 'academic_year_id' => $f->year->id, 'semester' => 1, 'note' => 'Baik'];
    DB::table('diniyah_grades')->insert($grade);
    DB::table('diniyah_report_notes')->insert($note);

    expect(fn () => DB::table('diniyah_grades')->insert($grade))->toThrow(QueryException::class)
        ->and(fn () => DB::table('diniyah_report_notes')->insert($note))->toThrow(QueryException::class)
        ->and(fn () => DB::table('diniyah_subjects')->insert(['unit_id' => $f->sltp->id, 'name' => 'Aqidah', 'sort_order' => 9, 'is_active' => true]))->toThrow(QueryException::class)
        ->and(DB::table('diniyah_grades')->insert([...$grade, 'semester' => 2]))->toBeTrue();
});

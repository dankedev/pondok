<?php

use App\Filament\Pages\DiniyahGradeEntry;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\DiniyahSubject;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Kontrak page (F096): slug `diniyah-grade-entry`, state `data.mode` (`classroom` default | `student`),
| `data.classroom_id`, `data.semester` (1|2), `data.subject_id` (mode rombel), `data.student_id` (mode santri),
| `data.scores.<student_id>.<subject_id>`, `data.notes.<student_id>` (hanya mode santri).
| Aksi publik `save()` (Simpan semua / Simpan nilai sesuai mode) dan `saveRow(int $studentId)` (mode rombel).
| Penanda KKM: helper "KKM :kkm" dan hint "Di bawah KKM" dihitung server dari nilai tersimpan.
| Tahun ajaran non-aktif tetap boleh diedit.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

/**
 * Mode rombel: rombel + semester + mapel terpilih (urutan penting, ganti rombel mengosongkan mapel).
 */
function diniyahRoomEntry(Classroom $classroom, ?DiniyahSubject $subject = null, int $semester = 1): Testable
{
    $component = Livewire::test(DiniyahGradeEntry::class)
        ->set('data.classroom_id', $classroom->id)
        ->set('data.semester', $semester);

    return $subject instanceof DiniyahSubject ? $component->set('data.subject_id', $subject->id) : $component;
}

/**
 * Mode santri: rombel + semester + santri terpilih.
 */
function diniyahStudentEntry(Classroom $classroom, int|string|null $studentId = null, int $semester = 1): Testable
{
    $component = Livewire::test(DiniyahGradeEntry::class)
        ->set('data.mode', 'student')
        ->set('data.classroom_id', $classroom->id)
        ->set('data.semester', $semester);

    return $studentId === null ? $component : $component->set('data.student_id', $studentId);
}

/**
 * Opsi (kunci) sebuah field form halaman.
 *
 * @return list<int>
 */
function diniyahFieldOptionKeys(Testable $component, string $field): array
{
    $fields = $component->instance()->getSchema('form')->getFlatFields(withHidden: true);
    $key = collect(array_keys($fields))->first(fn (string $name): bool => $name === $field || str_ends_with($name, ".{$field}"));

    return collect($fields[$key]->getOptions())->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

it('AC-9: opens the entry page only for holders of input-diniyah-grades', function (string $account, bool $allowed) {
    $f = $this->f;
    $user = property_exists($f, $account) ? $f->{$account} : asramaUser($account);
    $this->actingAs($user);

    expect(DiniyahGradeEntry::canAccess())->toBe($allowed);
    $this->get('/admin/diniyah-grade-entry')->assertStatus($allowed ? 200 : 403);
})->with([
    'wali-kelas' => ['homeroom', true],
    'admin-sekolah' => ['admin', true],
    'kesantrian' => ['kes', false],
    'musyrif' => ['musyrif', false],
    'bendahara' => ['bendahara', false],
    'wali' => ['wali', false],
]);

it('AC-4: starts in classroom mode and lists one subject for the roster only after the subject is picked', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 55);
    $this->actingAs($f->homeroom);

    $component = diniyahRoomEntry($f->c7a)->assertSet('data.mode', 'classroom');

    expect(diniyahFieldOptionKeys($component, 'subject_id'))->toBe(collect([$f->fiqih, $f->aqidah, $f->nahwu])->pluck('id')->sort()->values()->all())
        ->and(tagihanPlainText($component))->not->toContain('Santri Satu')->not->toContain('Simpan semua');

    $component->set('data.subject_id', $f->aqidah->id)->assertFormSet(["scores.{$f->s1->id}.{$f->aqidah->id}" => 70]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Santri Satu')->toContain('Santri Dua')->toContain('Santri Tiga')->toContain('Simpan semua')
        ->not->toContain('Santri Lulus')->not->toContain('Santri Tujuh B')
        ->and($component->instance()->data['scores'][$f->s1->id] ?? [])->not->toHaveKey($f->fiqih->id);
});

it('AC-4: uses the Enter key for nothing in classroom mode and submits only in student mode', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    expect(diniyahRoomEntry($f->c7a, $f->aqidah)->html())->not->toContain('wire:submit="save"')
        ->and(diniyahStudentEntry($f->c7a, $f->s1->id)->html())->toContain('wire:submit="save"');
});

it('AC-5: saves every row of the picked subject, clears empty cells and leaves other subjects and notes alone', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    diniyahGrade($f->s3, $f->year, 1, $f->aqidah, 40);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 80);
    diniyahNote($f->s1, $f->year, 1, 'Lama');
    $this->actingAs($f->homeroom);

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 95)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 60)
        ->set("data.scores.{$f->s3->id}.{$f->aqidah->id}", null)
        ->set("data.scores.{$f->s1->id}.{$f->fiqih->id}", 1)
        ->set("data.notes.{$f->s1->id}", 'Catatan palsu')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 95, $f->fiqih->id => 80])
        ->and(diniyahStored($f->s2))->toBe([$f->aqidah->id => 60])
        ->and(diniyahStored($f->s3))->toBe([])
        ->and(DiniyahGrade::query()->where('diniyah_subject_id', $f->aqidah->id)->pluck('recorded_by')->unique()->all())->toBe([$f->homeroom->id])
        ->and(DiniyahReportNote::query()->sole()->note)->toBe('Lama');
});

it('AC-5: saves nothing and flags the row cell when one score is above 100', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 101)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 80)
        ->call('save')
        ->assertHasErrors(["data.scores.{$f->s1->id}.{$f->aqidah->id}"]);

    expect(DiniyahGrade::query()->count())->toBe(0);
});

it('AC-6: saves one row only, ignores unsaved and invalid cells of other rows, and clears just that row', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    diniyahGrade($f->s2, $f->year, 1, $f->aqidah, 50);
    $this->actingAs($f->homeroom);
    $subjectId = $f->aqidah->id;

    $component = diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$subjectId}", 88)
        ->set("data.scores.{$f->s2->id}.{$subjectId}", 10)
        ->set("data.scores.{$f->s3->id}.{$subjectId}", 101)
        ->call('saveRow', $f->s1->id)
        ->assertHasNoErrors()
        ->assertNotified();

    expect(diniyahStored($f->s1))->toBe([$subjectId => 88])
        ->and(diniyahStored($f->s2))->toBe([$subjectId => 50])
        ->and(diniyahStored($f->s3))->toBe([]);

    $component->set("data.scores.{$f->s1->id}.{$subjectId}", null)->call('saveRow', $f->s1->id);

    expect(diniyahStored($f->s1))->toBe([])
        ->and(diniyahStored($f->s2))->toBe([$subjectId => 50]);

    $component->set("data.scores.{$f->s1->id}.{$subjectId}", 101)
        ->call('saveRow', $f->s1->id)
        ->assertHasErrors(["data.scores.{$f->s1->id}.{$subjectId}"]);

    expect(diniyahStored($f->s1))->toBe([]);
});

it('AC-6: refuses saveRow for a student of another classroom, a subject of another unit or the student mode', function () {
    $f = $this->f;
    $this->actingAs($f->admin);
    $before = diniyahSnapshot();

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->o7b->id}.{$f->aqidah->id}", 99)
        ->call('saveRow', $f->o7b->id);

    expect(diniyahSnapshot())->toBe($before);

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set('data.subject_id', $f->hadits->id)
        ->set("data.scores.{$f->s1->id}.{$f->hadits->id}", 99)
        ->call('saveRow', $f->s1->id);

    expect(diniyahSnapshot())->toBe($before);

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 99)
        ->set('data.mode', 'student')
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 99)
        ->call('saveRow', $f->s1->id);

    expect(diniyahSnapshot())->toBe($before);
});

it('AC-7: student mode lists the roster, loads every active subject with the note and print link, then saves only that student', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    diniyahGrade($f->s2, $f->year, 1, $f->aqidah, 55);
    diniyahNote($f->s2, $f->year, 1, 'Catatan dua');
    $this->actingAs($f->homeroom);

    $component = diniyahStudentEntry($f->c7a);

    expect(diniyahFieldOptionKeys($component, 'student_id'))->toBe(collect([$f->s1, $f->s2, $f->s3])->pluck('id')->sort()->values()->all());

    $component->set('data.student_id', $f->s1->id)->assertFormSet(["scores.{$f->s1->id}.{$f->aqidah->id}" => 70]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Aqidah')->toContain('Fiqih')->toContain('Nahwu')->toContain('Simpan nilai')
        ->not->toContain('Sharaf')->not->toContain('Hadits')
        ->and($component->html())->toContain("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")
        ->not->toContain("/admin/akademik/rapor/{$f->s3->id}/{$f->year->id}/1");

    $component
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 95)
        ->set("data.scores.{$f->s1->id}.{$f->fiqih->id}", 82)
        ->set("data.scores.{$f->s1->id}.{$f->nahwu->id}", 0)
        ->set("data.notes.{$f->s1->id}", 'Perlu menambah hafalan')
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 1)
        ->set("data.scores.{$f->s3->id}.{$f->fiqih->id}", 1)
        ->set("data.notes.{$f->s2->id}", 'Palsu')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 95, $f->fiqih->id => 82, $f->nahwu->id => 0])
        ->and(diniyahStored($f->s2))->toBe([$f->aqidah->id => 55])
        ->and(diniyahStored($f->s3))->toBe([])
        ->and(DiniyahReportNote::query()->where('student_id', $f->s1->id)->sole()->note)->toBe('Perlu menambah hafalan')
        ->and(DiniyahReportNote::query()->where('student_id', $f->s2->id)->sole()->note)->toBe('Catatan dua');

    $component->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", null)->set("data.notes.{$f->s1->id}", '')->call('save');

    expect(array_keys(diniyahStored($f->s1)))->not->toContain($f->aqidah->id)
        ->and(DiniyahReportNote::query()->where('student_id', $f->s1->id)->exists())->toBeFalse();
});

it('AC-8: loads and saves nothing for a forged student id', function (string $forged) {
    $f = $this->f;
    $this->actingAs($f->admin);
    $before = diniyahSnapshot();
    $studentId = $f->{$forged}->id;

    $component = diniyahStudentEntry($f->c7a, $studentId)
        ->set("data.scores.{$studentId}.{$f->aqidah->id}", 99)
        ->set("data.notes.{$studentId}", 'Palsu')
        ->call('save');

    expect($component->instance()->data['scores'][$studentId] ?? [])->toBe([])
        ->and(diniyahSnapshot())->toBe($before);
})->with(['other classroom' => 'o7b', 'other unit' => 'o10', 'inactive student' => 'grad']);

it('AC-9: does not save for a classroom outside the scope of the user in either mode', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    diniyahRoomEntry($f->c7b, $f->aqidah)
        ->set("data.scores.{$f->o7b->id}.{$f->aqidah->id}", 80)
        ->call('save')
        ->call('saveRow', $f->o7b->id);

    diniyahStudentEntry($f->c7b, $f->o7b->id)
        ->set("data.scores.{$f->o7b->id}.{$f->aqidah->id}", 80)
        ->call('save');

    expect(DiniyahGrade::query()->count())->toBe(0);

    $this->actingAs($f->adminSltp);

    diniyahRoomEntry($f->c10a, $f->hadits)
        ->set("data.scores.{$f->o10->id}.{$f->hadits->id}", 80)
        ->call('save');

    expect(DiniyahGrade::query()->count())->toBe(0);
});

it('AC-5: lets admin-sekolah edit a classroom of a year that is no longer active', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    expect($f->prev->is_active)->toBeFalse();

    diniyahRoomEntry($f->cOld, $f->aqidah, 2)
        ->set("data.scores.{$f->old->id}.{$f->aqidah->id}", 77)
        ->call('save')
        ->assertHasNoErrors();

    expect(diniyahStored($f->old, 2))->toBe([$f->aqidah->id => 77]);
});

it('AC-4: shows an empty state when the unit has no active subject', function () {
    $f = $this->f;
    $f->hadits->forceFill(['is_active' => false])->save();
    $this->actingAs($f->admin);

    expect(tagihanPlainText(diniyahRoomEntry($f->c10a)))->toContain('Belum ada mapel aktif untuk jenjang ini')
        ->and(tagihanPlainText(diniyahStudentEntry($f->c10a, $f->o10->id)))->toContain('Belum ada mapel aktif untuk jenjang ini');
});

it('AC-7: links each student to the printable report in both modes', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    $html = diniyahRoomEntry($f->c7a, $f->aqidah, 2)->html();

    expect($html)->toContain("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/2")
        ->toContain("/admin/akademik/rapor/{$f->s3->id}/{$f->year->id}/2");
});

it('AC-10: belongs to akademik and closes in both modes with the module while grades and passing scores stay', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 70])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);

    expect(DiniyahGradeEntry::getModule())->toBe('akademik');

    Setting::set('modules.akademik.enabled', false);
    $this->actingAs($f->admin);

    expect(DiniyahGradeEntry::canAccess())->toBeFalse()
        ->and($this->get('/admin/diniyah-grade-entry')->status())->toBeIn([403, 404])
        ->and($this->get('/admin/diniyah-subjects')->status())->toBeIn([403, 404])
        ->and(DiniyahGrade::query()->count())->toBe(1)
        ->and($f->aqidah->fresh()->passing_score)->toBe(70);

    Livewire::test(DiniyahGradeEntry::class)->assertForbidden();
});

it('AC-11: flags a score below the passing score in classroom mode, before and after saving', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 70])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 65);
    diniyahGrade($f->s2, $f->year, 1, $f->nahwu, 10);
    $this->actingAs($f->homeroom);

    $component = diniyahRoomEntry($f->c7a, $f->aqidah);
    $text = tagihanPlainText($component);

    expect($text)->toContain('KKM 70')->toContain('Di bawah KKM');

    $component->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 70)->call('save');

    expect(tagihanPlainText($component))->toContain('KKM 70')->not->toContain('Di bawah KKM');

    $component->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 65)->call('save');

    expect(tagihanPlainText($component))->toContain('Di bawah KKM');

    $noKkm = tagihanPlainText(diniyahRoomEntry($f->c7a, $f->nahwu));

    expect($noKkm)->toContain('Santri Dua')->not->toContain('KKM');
});

it('AC-11: flags a score below the passing score in student mode only for subjects with a passing score', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 70])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 65);
    diniyahGrade($f->s1, $f->year, 1, $f->nahwu, 10);
    $this->actingAs($f->homeroom);

    $component = diniyahStudentEntry($f->c7a, $f->s1->id);
    $text = tagihanPlainText($component);

    expect($text)->toContain('KKM 70')->toContain('Di bawah KKM');

    $component->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 70)->call('save');

    expect(tagihanPlainText($component))->toContain('KKM 70')->not->toContain('Di bawah KKM');
});

/*
| Verifikasi adversarial F096: nilai kotor lewat saveRow, state yang disuntik, penanda KKM, migration.
*/

it('F096: saveRow rejects string, array and negative scores from the client and stores nothing', function (mixed $value) {
    $f = $this->f;
    $this->actingAs($f->admin);
    $before = diniyahSnapshot();

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", $value)
        ->call('saveRow', $f->s1->id);

    expect(diniyahSnapshot())->toBe($before);
})->with(['letters' => 'abc', 'negative' => -5, 'decimal' => '85.5', 'array' => [[85]], 'script' => '<script>1</script>']);

it('F096: saveRow keeps the unsaved input of other rows in the sheet', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    $component = diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 88)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 10)
        ->call('saveRow', $f->s1->id);

    expect($component->instance()->data['scores'][$f->s2->id][$f->aqidah->id])->toBe(10)
        ->and(diniyahStored($f->s2))->toBe([]);
});

it('F096: refuses an inactive subject injected into the state in both save paths', function () {
    $f = $this->f;
    $this->actingAs($f->admin);
    $before = diniyahSnapshot();

    diniyahRoomEntry($f->c7a)
        ->set('data.subject_id', $f->sharaf->id)
        ->set("data.scores.{$f->s1->id}.{$f->sharaf->id}", 90)
        ->call('save')
        ->call('saveRow', $f->s1->id);

    diniyahStudentEntry($f->c7a, $f->s1->id)
        ->set("data.scores.{$f->s1->id}.{$f->sharaf->id}", 90)
        ->call('save');

    expect(diniyahSnapshot())->toBe($before);
});

it('F096: does not save a classroom or subject of another unit injected after a legitimate pick by a unit-scoped admin', function () {
    $f = $this->f;
    $this->actingAs($f->adminSltp);
    $before = diniyahSnapshot();

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set('data.classroom_id', $f->c10a->id)
        ->set("data.scores.{$f->o10->id}.{$f->aqidah->id}", 90)
        ->call('save')
        ->call('saveRow', $f->o10->id);

    diniyahRoomEntry($f->c7a, $f->aqidah)
        ->set('data.subject_id', $f->hadits->id)
        ->set("data.scores.{$f->s1->id}.{$f->hadits->id}", 90)
        ->call('save');

    expect(diniyahSnapshot())->toBe($before);
});

it('F096: student mode ignores injected scores for subjects of another unit and for other students', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);
    $before = diniyahSnapshot();

    diniyahStudentEntry($f->c7a, $f->s1->id)
        ->set("data.scores.{$f->s1->id}.{$f->hadits->id}", 90)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 90)
        ->set("data.scores.{$f->o7b->id}.{$f->aqidah->id}", 90)
        ->call('save');

    expect(diniyahSnapshot()['grades'])->toBe($before['grades']);
});

it('F096: marks below-KKM from stored scores only, at the exact boundary, and never edits the KKM when saving', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 70])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 69);
    diniyahGrade($f->s2, $f->year, 1, $f->aqidah, 70);
    $this->actingAs($f->homeroom);

    $component = diniyahRoomEntry($f->c7a, $f->aqidah);

    expect(substr_count($component->html(), 'Di bawah KKM'))->toBe(1);

    $component->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 10);

    expect(substr_count($component->html(), 'Di bawah KKM'))->toBe(1);

    $component->call('save');

    expect(substr_count($component->html(), 'Di bawah KKM'))->toBe(2)
        ->and($f->aqidah->fresh()->passing_score)->toBe(70);
});

it('F096: shows no below-KKM mark for a subject without KKM, even for a score of 0', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 0);
    $this->actingAs($f->homeroom);

    expect(diniyahRoomEntry($f->c7a, $f->aqidah)->html())->not->toContain('Di bawah KKM');
});

it('F096: builds the report card of a student whose subject became inactive while it has a KKM', function () {
    $f = $this->f;
    $f->aqidah->forceFill(['passing_score' => 80, 'is_active' => false])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 50);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 90);
    $this->actingAs($f->admin);

    $card = diniyahCard($f->s1, $f->year);

    expect($card['rows'])->toBeArray()->not->toBeEmpty();
    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")->assertOk();
});

it('F096: rolls the passing score migration back and forward on SQLite', function () {
    $migration = require base_path('database/migrations/2026_11_04_000001_add_passing_score_to_diniyah_subjects_table.php');

    $migration->down();
    expect(Schema::hasColumn('diniyah_subjects', 'passing_score'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('diniyah_subjects', 'passing_score'))->toBeTrue();
});

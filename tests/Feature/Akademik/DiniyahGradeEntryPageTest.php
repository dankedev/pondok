<?php

use App\Filament\Pages\DiniyahGradeEntry;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\Setting;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Kontrak page: slug `diniyah-grade-entry`, state `data.classroom_id`, `data.semester` (1|2),
| `data.scores.<student_id>.<subject_id>`, `data.notes.<student_id>`, aksi `save`.
| Baris santri = santri aktif rombel; kolom = mapel aktif jenjang rombel. Tahun ajaran non-aktif tetap boleh diedit.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

function diniyahEntry(Classroom $classroom, int $semester = 1): Testable
{
    return Livewire::test(DiniyahGradeEntry::class)
        ->set('data.classroom_id', $classroom->id)
        ->set('data.semester', $semester);
}

it('AC-7: opens the entry page only for holders of input-diniyah-grades', function (string $account, bool $allowed) {
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

it('AC-3: loads the sheet with active students and active subjects only, then saves scores and notes', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    $this->actingAs($f->homeroom);

    $component = diniyahEntry($f->c7a)
        ->assertFormSet(["scores.{$f->s1->id}.{$f->aqidah->id}" => 70]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Santri Satu')->toContain('Santri Tiga')->toContain('Aqidah')->toContain('Nahwu')
        ->not->toContain('Santri Lulus')->not->toContain('Santri Tujuh B')
        ->not->toContain('Sharaf')->not->toContain('Hadits');

    $component
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 95)
        ->set("data.scores.{$f->s1->id}.{$f->fiqih->id}", 82)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 60)
        ->set("data.notes.{$f->s1->id}", 'Perlu menambah hafalan')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(diniyahStored($f->s1))->toBe([$f->aqidah->id => 95, $f->fiqih->id => 82])
        ->and(diniyahStored($f->s2))->toBe([$f->aqidah->id => 60])
        ->and(DiniyahGrade::query()->pluck('recorded_by')->unique()->all())->toBe([$f->homeroom->id])
        ->and(DiniyahReportNote::query()->sole()->note)->toBe('Perlu menambah hafalan');
});

it('AC-5: clearing a cell on the sheet deletes the stored score', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 80);
    diniyahNote($f->s1, $f->year, 1, 'Lama');
    $this->actingAs($f->homeroom);

    diniyahEntry($f->c7a)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", null)
        ->set("data.notes.{$f->s1->id}", '')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(diniyahStored($f->s1))->toBe([$f->fiqih->id => 80])
        ->and(DiniyahReportNote::query()->count())->toBe(0);
});

it('AC-6: saves nothing when one score is above 100', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    diniyahEntry($f->c7a)
        ->set("data.scores.{$f->s1->id}.{$f->aqidah->id}", 101)
        ->set("data.scores.{$f->s2->id}.{$f->aqidah->id}", 80)
        ->call('save');

    expect(DiniyahGrade::query()->count())->toBe(0);
});

it('AC-7: does not save for a classroom outside the scope of the user', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    diniyahEntry($f->c7b)
        ->set("data.scores.{$f->o7b->id}.{$f->aqidah->id}", 80)
        ->call('save');

    expect(DiniyahGrade::query()->count())->toBe(0);

    $this->actingAs($f->adminSltp);

    diniyahEntry($f->c10a)
        ->set("data.scores.{$f->o10->id}.{$f->hadits->id}", 80)
        ->call('save');

    expect(DiniyahGrade::query()->count())->toBe(0);
});

it('AC-7: lets admin-sekolah edit a classroom of a year that is no longer active', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    expect($f->prev->is_active)->toBeFalse();

    diniyahEntry($f->cOld, 2)
        ->set("data.scores.{$f->old->id}.{$f->aqidah->id}", 77)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(diniyahStored($f->old, 2))->toBe([$f->aqidah->id => 77]);
});

it('AC-1: shows an empty state when the unit has no active subject', function () {
    $f = $this->f;
    $f->hadits->forceFill(['is_active' => false])->save();
    $this->actingAs($f->admin);

    expect(tagihanPlainText(diniyahEntry($f->c10a)))->toContain('Belum ada mapel aktif untuk jenjang ini');
});

it('AC-9: links each student to the printable report', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    $html = diniyahEntry($f->c7a, 2)->html();

    expect($html)->toContain("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/2")
        ->toContain("/admin/akademik/rapor/{$f->s3->id}/{$f->year->id}/2");
});

it('AC-11: belongs to akademik and closes with the module while the data stays', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 70);

    expect(DiniyahGradeEntry::getModule())->toBe('akademik');

    Setting::set('modules.akademik.enabled', false);
    $this->actingAs($f->admin);

    expect(DiniyahGradeEntry::canAccess())->toBeFalse()
        ->and($this->get('/admin/diniyah-grade-entry')->status())->toBeIn([403, 404])
        ->and($this->get('/admin/diniyah-subjects')->status())->toBeIn([403, 404])
        ->and(DiniyahGrade::query()->count())->toBe(1);
});

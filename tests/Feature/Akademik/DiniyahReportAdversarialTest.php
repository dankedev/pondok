<?php

use App\Filament\Pages\DiniyahGradeEntry;
use App\Filament\Resources\DiniyahSubjects\Pages\CreateDiniyahSubject;
use App\Models\DiniyahSubject;
use App\Models\Staff;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Verifikasi adversarial F027: celah di luar test RED (batas catatan di Action, format nilai,
| escape XSS di cetak, rapor hanya catatan, cakupan jenjang pada pembuatan mapel).
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

it('rejects a note longer than a text column holds instead of failing in the database', function () {
    $f = $this->f;
    $before = diniyahSnapshot();

    expect(fn () => diniyahSave($f->admin, $f->c7a, 1, [], [$f->s1->id => str_repeat('a', 70000)]))
        ->toThrow(ValidationException::class)
        ->and(diniyahSnapshot())->toBe($before);
});

it('rejects look-alike score formats and stores nothing', function () {
    $f = $this->f;
    $before = diniyahSnapshot();

    foreach (['٨٥', '８５', '1e2', '+85', '-0', '8 5', '85.0', '0x55', true, 85.0, [85]] as $value) {
        $rejected = false;

        try {
            diniyahSave($f->admin, $f->c7a, 1, [$f->s1->id => [$f->aqidah->id => $value]]);
        } catch (ValidationException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue('nilai '.json_encode($value).' seharusnya ditolak');
    }

    expect(diniyahSnapshot())->toBe($before);
});

it('rejects a boolean or fractional semester', function () {
    $f = $this->f;

    foreach (['1.5', 'abc', null, [1]] as $semester) {
        $rejected = false;

        try {
            diniyahSave($f->admin, $f->c7a, $semester, [$f->s1->id => [$f->aqidah->id => 80]]);
        } catch (ValidationException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue('semester '.json_encode($semester).' seharusnya ditolak');
    }

    expect(diniyahSnapshot()['grades'])->toBe([]);
});

it('escapes subject, student, classroom and homeroom names on the printed report', function () {
    $f = $this->f;
    $payload = '<img src=x onerror=alert(1)>';
    $f->aqidah->forceFill(['name' => $payload])->save();
    $f->s1->forceFill(['name' => $payload])->save();
    $f->c7a->forceFill(['name' => $payload])->save();
    $f->homeroomStaff->forceFill(['name' => $payload])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 88);

    $html = $this->actingAs($f->admin)->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")->assertOk()->getContent();

    expect($html)->not->toContain($payload)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('prints a note-only report with a dash average for admin', function () {
    $f = $this->f;
    diniyahNote($f->s1, $f->year, 1, 'Perlu bimbingan membaca');

    $text = bayarHtmlText($this->actingAs($f->admin)->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")->assertOk()->getContent());

    expect($text)->toContain('Perlu bimbingan membaca')->not->toContain('NaN');
});

it('does not expose a past-year report when the student has grades but no enrollment that year', function () {
    $f = $this->f;
    diniyahGrade($f->s1, $f->prev, 1, $f->aqidah, 77);

    $this->actingAs($f->admin)->get("/admin/akademik/rapor/{$f->s1->id}/{$f->prev->id}/1")->assertNotFound();
});

it('keeps a unit-scoped admin from creating a subject in a unit outside the scope', function () {
    $f = $this->f;
    $this->actingAs($f->adminSltp);

    Livewire::test(CreateDiniyahSubject::class)
        ->fillForm(['unit_id' => $f->slta->id, 'name' => 'Akhlak', 'sort_order' => 1, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['unit_id']);

    expect(DiniyahSubject::withoutUnitScope()->where('name', 'Akhlak')->exists())->toBeFalse();
});

it('AC-9: blocks another homeroom teacher in both modes and on saveRow', function () {
    $f = $this->f;
    $f->homeroomStaff->forceFill(['user_id' => null])->save();
    $other = asramaUser('wali-kelas');
    $f->c7b->forceFill(['homeroom_staff_id' => Staff::factory()->create(['user_id' => $other->id, 'gender' => 'L', 'position' => 'ustadz'])->id])->save();
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 50);
    diniyahNote($f->s1, $f->year, 1, 'Lama');
    $this->actingAs($other);
    $before = diniyahSnapshot();
    $scores = [$f->s1->id => [$f->aqidah->id => 99]];

    $room = Livewire::test(DiniyahGradeEntry::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.subject_id', $f->aqidah->id)
        ->set('data.scores', $scores)
        ->call('save')
        ->call('saveRow', $f->s1->id);

    expect($room->instance()->data['scores'][$f->s1->id][$f->aqidah->id] ?? null)->toBe(99)
        ->and(tagihanPlainText($room))->not->toContain('Santri Satu');

    Livewire::test(DiniyahGradeEntry::class)
        ->set('data.mode', 'student')
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.student_id', $f->s1->id)
        ->set('data.scores', $scores)
        ->set('data.notes', [$f->s1->id => 'Palsu'])
        ->call('save');

    expect(diniyahSnapshot())->toBe($before);
});

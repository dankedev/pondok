<?php

use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Filament\Pages\DisciplineRecap;
use App\Filament\Resources\StudentViolations\Pages\CreateStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\EditStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\ListStudentViolations;
use App\Models\ViolationType;
use Database\Seeders\SystemSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

it('VERIFY: rejects a forged student_id in the create form for a musyrif and a unit scoped kesantrian', function () {
    $f = $this->f;

    foreach ([[$f->musyrif, $f->s2], [$f->musyrif, $f->s4], [$f->kesSltp, $f->s4]] as [$user, $student]) {
        $this->actingAs($user);

        Livewire::test(CreateStudentViolation::class)
            ->fillForm(['student_id' => $student->id, 'violation_type_id' => $f->ringan->id, 'occurred_on' => '2026-10-14'])
            ->call('create')
            ->assertHasFormErrors(['student_id']);
    }

    expect(disiplinCount())->toBe(0);
});

it('VERIFY: rejects a forged or inactive violation_type_id in the create form', function () {
    $f = $this->f;
    $inactive = disiplinType('Jenis nonaktif', 'ringan', 5, ['is_active' => false]);
    $this->actingAs($f->kes);

    foreach ([$inactive->id, 99999] as $typeId) {
        Livewire::test(CreateStudentViolation::class)
            ->fillForm(['student_id' => $f->s1->id, 'violation_type_id' => $typeId, 'occurred_on' => '2026-10-14'])
            ->call('create')
            ->assertHasFormErrors(['violation_type_id']);
    }

    expect(disiplinCount())->toBe(0);
});

it('VERIFY: ignores a forged student_id in the edit form payload', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->kes);

    Livewire::test(EditStudentViolation::class, ['record' => $violation->getRouteKey()])
        ->set('data.student_id', $f->s2->id)
        ->set('data.violation_type_id', $f->sedang->id)
        ->call('save');

    $violation->refresh();

    expect($violation->student_id)->toBe($f->s1->id)->and($violation->points)->toBe(25);
});

it('VERIFY: refuses the edit page of a cancelled violation and of another unit', function () {
    $f = $this->f;
    $cancelled = disiplinRawCancelled($f, $f->s1, $f->ringan);
    $other = disiplinRaw($f, $f->s4, $f->ringan);

    $this->actingAs($f->kes)->get("/admin/student-violations/{$cancelled->id}/edit")->assertForbidden();
    $this->get("/admin/student-violations/{$cancelled->id}")->assertOk();

    $this->actingAs($f->kesSltp)->get("/admin/student-violations/{$other->id}")->assertNotFound();
    $this->get("/admin/student-violations/{$other->id}/edit")->assertNotFound();
});

it('VERIFY: refuses a musyrif to open the view page of a violation outside the supervised dormitory', function () {
    $f = $this->f;
    $own = disiplinRaw($f, $f->s1, $f->ringan);
    $other = disiplinRaw($f, $f->s2, $f->ringan);

    $this->actingAs($f->musyrif)->get("/admin/student-violations/{$own->id}")->assertOk();
    $this->get("/admin/student-violations/{$other->id}")->assertNotFound();
    $this->get("/admin/student-violations/{$other->id}/edit")->assertNotFound();
});

it('VERIFY: rejects edits to a future date or to another inactive type and leaves the row untouched', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);
    $inactive = disiplinType('Jenis nonaktif', 'ringan', 9, ['is_active' => false]);
    $before = $violation->getAttributes();

    foreach (['occurred_on' => '2026-10-16', 'violation_type_id' => $inactive->id] as $field => $value) {
        $this->actingAs($f->kes);
        tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, [$field => $value]), $violation), $field);
    }

    expect($violation->fresh()->getAttributes())->toEqual($before)->and(disiplinActivities())->toHaveCount(0);
});

it('VERIFY: rejects malformed scalar and array input without a server error', function () {
    $f = $this->f;

    foreach ([
        ['occurred_on', 'bukan-tanggal'], ['occurred_on', ['2026-10-14']], ['violation_type_id', [$f->ringan->id]],
        ['violation_type_id', 'abc'], ['description', ['x']], ['student_id', [$f->s1->id]], ['student_id', null],
    ] as [$field, $value]) {
        $this->actingAs($f->kes);
        tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, [$field => $value])), $field);
    }

    expect(disiplinCount())->toBe(0);
});

it('VERIFY: ignores a forged unit filter on the recap page outside the unit scope', function () {
    $f = $this->f;
    disiplinRaw($f, $f->s1, $f->ringan);
    disiplinRaw($f, $f->s4, $f->ringan);
    $this->actingAs($f->kesSltp);

    Livewire::test(DisciplineRecap::class)
        ->set('data.unit_id', $f->slta->id)
        ->assertDontSee('Santri Empat')->assertDontSee('Santri Satu')
        ->set('data.unit_id', 'bukan-angka')
        ->assertOk()->assertSee('Santri Satu')->assertDontSee('Santri Empat');
});

it('VERIFY: keeps the records of an archived student in the list but out of the recap', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);
    $f->s1->delete();
    $this->actingAs($f->kes);

    Livewire::test(ListStudentViolations::class)->assertOk()->assertCanSeeTableRecords([$violation]);
    $this->get("/admin/student-violations/{$violation->id}")->assertOk();
    Livewire::test(DisciplineRecap::class)->assertOk()->assertDontSee('Santri Satu');
});

it('VERIFY: does not seed the catalog again through the upgrade seeder', function () {
    $f = $this->f;
    $renamed = disiplinType('Nama diubah admin', 'ringan', 3);

    $this->seed(SystemSeeder::class);

    expect(ViolationType::query()->pluck('name')->all())->toEqualCanonicalizing([
        $f->ringan->name, $f->sedang->name, $f->berat->name, $renamed->name,
    ]);
});

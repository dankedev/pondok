<?php

use App\Filament\Resources\DiniyahSubjects\DiniyahSubjectResource;
use App\Filament\Resources\DiniyahSubjects\Pages\CreateDiniyahSubject;
use App\Filament\Resources\DiniyahSubjects\Pages\EditDiniyahSubject;
use App\Filament\Resources\DiniyahSubjects\Pages\ListDiniyahSubjects;
use App\Models\AcademicYear;
use App\Models\DiniyahSubject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Kontrak form: field `unit_id`, `name`, `sort_order`, `is_active`. Resource di grup Akademik, tanpa aksi hapus.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
});

it('AC-1: lets admin-sekolah and kesantrian create and edit subjects per unit', function (string $account) {
    $f = $this->f;
    $this->actingAs($f->{$account});

    $this->get('/admin/diniyah-subjects')->assertOk();

    Livewire::test(CreateDiniyahSubject::class)
        ->fillForm(['unit_id' => $f->sltp->id, 'name' => 'Akhlak', 'sort_order' => 5, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $subject = DiniyahSubject::query()->where('name', 'Akhlak')->sole();

    expect($subject->unit_id)->toBe($f->sltp->id)->and($subject->is_active)->toBeTrue();

    Livewire::test(EditDiniyahSubject::class, ['record' => $subject->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subject->fresh()->is_active)->toBeFalse();
})->with(['admin', 'kes']);

it('AC-1: rejects a duplicate name in the same unit but allows it in another unit', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(CreateDiniyahSubject::class)
        ->fillForm(['unit_id' => $f->sltp->id, 'name' => 'Aqidah', 'sort_order' => 9, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['name']);

    Livewire::test(CreateDiniyahSubject::class)
        ->fillForm(['unit_id' => $f->slta->id, 'name' => 'Aqidah', 'sort_order' => 2, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DiniyahSubject::query()->where('name', 'Aqidah')->count())->toBe(2);
});

it('AC-1: lists subjects without a delete action and shows the status', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListDiniyahSubjects::class)
        ->assertCanSeeTableRecords([$f->aqidah, $f->sharaf, $f->hadits])
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('AC-1: keeps wali-kelas out of the subject resource', function () {
    $f = $this->f;
    $this->actingAs($f->homeroom);

    expect(DiniyahSubjectResource::canViewAny())->toBeFalse()
        ->and(DiniyahSubjectResource::canCreate())->toBeFalse();
    $this->get('/admin/diniyah-subjects')->assertForbidden();
    $this->get('/admin/diniyah-subjects/create')->assertForbidden();
    $this->get("/admin/diniyah-subjects/{$f->aqidah->id}/edit")->assertForbidden();
});

it('AC-1: shows only the subjects of the unit scope of the user', function () {
    $f = $this->f;

    $this->actingAs($f->adminSltp);
    Livewire::test(ListDiniyahSubjects::class)
        ->assertCanSeeTableRecords([$f->aqidah, $f->fiqih])
        ->assertCanNotSeeTableRecords([$f->hadits]);

    $this->actingAs($f->admin);
    Livewire::test(ListDiniyahSubjects::class)->assertCanSeeTableRecords([$f->aqidah, $f->hadits]);
});

it('AC-11: belongs to the akademik module', function () {
    expect(DiniyahSubjectResource::getModule())->toBe('akademik');
});

it('AC-12: refuses to delete a subject or an academic year that already has grades', function () {
    $f = $this->f;
    $year = AcademicYear::factory()->create();
    diniyahGrade($f->s1, $year, 1, $f->aqidah, 80);

    expect(fn () => DB::table('diniyah_subjects')->where('id', $f->aqidah->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('academic_years')->where('id', $year->id)->delete())->toThrow(QueryException::class)
        ->and(DB::table('diniyah_grades')->count())->toBe(1);
});

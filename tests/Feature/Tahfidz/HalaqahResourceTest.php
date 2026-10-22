<?php

use App\Filament\Resources\Halaqahs\HalaqahResource;
use App\Filament\Resources\Halaqahs\Pages\CreateHalaqah;
use App\Models\AcademicYear;
use App\Models\Halaqah;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = tahfidzBoot();
});

function tahfidzHalaqahForm(object $f, array $overrides = []): array
{
    return [
        'academic_year_id' => $f->year->id,
        'unit_id' => $f->sltp->id,
        'teacher_staff_id' => tahfidzStaff()->id,
        'name' => 'Halaqah Abu Bakar',
        'gender' => 'L',
        'schedule' => 'Ba\'da Subuh',
        'is_active' => true,
        ...$overrides,
    ];
}

it('AC-3: lets admin-sekolah create a halaqah', function () {
    $this->actingAs(asramaUser('admin-sekolah'));

    Livewire::test(CreateHalaqah::class)
        ->fillForm(tahfidzHalaqahForm($this->f))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Halaqah::query()->where('name', 'Halaqah Abu Bakar')->sole())
        ->academic_year_id->toBe($this->f->year->id)
        ->is_active->toBeTrue();
});

it('AC-3: rejects a duplicate name in the same year but not in another year', function () {
    $this->actingAs(asramaUser('admin-sekolah'));
    tahfidzHalaqah($this->f, null, ['name' => 'Halaqah Abu Bakar']);

    Livewire::test(CreateHalaqah::class)
        ->fillForm(tahfidzHalaqahForm($this->f))
        ->call('create')
        ->assertHasFormErrors(['name']);

    $nextYear = AcademicYear::factory()->create();
    tahfidzHalaqah((object) ['year' => $nextYear, 'sltp' => $this->f->sltp], null, ['name' => 'Halaqah Abu Bakar']);

    expect(Halaqah::query()->where('name', 'Halaqah Abu Bakar')->count())->toBe(2);
});

it('AC-3: rejects an inactive staff member as teacher', function () {
    $this->actingAs(asramaUser('admin-sekolah'));

    Livewire::test(CreateHalaqah::class)
        ->fillForm(tahfidzHalaqahForm($this->f, ['teacher_staff_id' => tahfidzStaff(['is_active' => false])->id]))
        ->call('create')
        ->assertHasFormErrors(['teacher_staff_id']);

    expect(Halaqah::query()->count())->toBe(0);
});

it('AC-3: lets admin-sekolah see every halaqah in scope and a teacher only the halaqah they teach', function () {
    $ustadz = tahfidzUstadz();
    $own = tahfidzHalaqah($this->f, $ustadz->staff);
    $other = tahfidzHalaqah($this->f);
    $page = tahfidzIndexPage(HalaqahResource::class);

    $this->actingAs(asramaUser('admin-sekolah'));
    Livewire::test($page)->assertCanSeeTableRecords([$own, $other]);

    $this->actingAs($ustadz);
    Livewire::test($page)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
});

it('AC-3: gives a teacher the list but forbids editing and opening another teacher\'s halaqah', function () {
    $ustadz = tahfidzUstadz();
    $own = tahfidzHalaqah($this->f, $ustadz->staff);
    $other = tahfidzHalaqah($this->f);

    $this->actingAs($ustadz)->get('/admin/halaqahs')->assertOk();
    expect($this->actingAs($ustadz)->get("/admin/halaqahs/{$other->id}/edit")->status())->toBeIn([403, 404])
        ->and($this->actingAs($ustadz)->get("/admin/halaqahs/{$other->id}")->status())->toBeIn([403, 404])
        ->and($this->actingAs($ustadz)->get("/admin/halaqahs/{$own->id}/edit")->status())->toBeIn([403, 404]);

    $this->actingAs(asramaUser('admin-sekolah'))->get("/admin/halaqahs/{$other->id}/edit")->assertOk();
});

<?php

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Cohort;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');

    $this->units = Unit::query()->pluck('id', 'code');
    $this->sdCohort = Cohort::factory()->create(['unit_id' => $this->units['SD'], 'entry_year' => 2026]);
    $this->sltpCohort = Cohort::factory()->create(['unit_id' => $this->units['SLTP'], 'entry_year' => 2026]);
});

function studentFormData(array $overrides = []): array
{
    return [
        'nis' => '2026001',
        'name' => 'Abdullah',
        'gender' => 'L',
        'birth_place' => 'Bandung',
        'birth_date' => '2015-01-10',
        'status' => 'aktif',
        'entered_on' => '2026-07-13',
        ...$overrides,
    ];
}

it('lists only the students of the units a scoped admin-sekolah handles', function () {
    $user = User::factory()->create()->assignRole('admin-sekolah');
    $user->units()->attach($this->units['SLTP']);
    $sdStudent = Student::factory()->forCohort($this->sdCohort)->create();
    $sltpStudent = Student::factory()->forCohort($this->sltpCohort)->create();

    $this->actingAs($user);

    Livewire::test(ListStudents::class)
        ->assertCanSeeTableRecords([$sltpStudent])
        ->assertCanNotSeeTableRecords([$sdStudent]);
});

it('returns 404 when a scoped admin opens a student from another unit', function () {
    $user = User::factory()->create()->assignRole('admin-sekolah');
    $user->units()->attach($this->units['SLTP']);
    $sdStudent = Student::factory()->forCohort($this->sdCohort)->create();

    $this->actingAs($user)->get("/admin/students/{$sdStudent->id}/edit")->assertNotFound();
});

it('creates a student in the selected unit and cohort', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));

    Livewire::test(CreateStudent::class)
        ->fillForm(studentFormData(['unit_id' => $this->units['SD'], 'cohort_id' => $this->sdCohort->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Student::query()->where('nis', '2026001')->sole())
        ->unit_id->toBe($this->units['SD'])
        ->cohort_id->toBe($this->sdCohort->id);
});

it('rejects a cohort from another unit on create', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));

    Livewire::test(CreateStudent::class)
        ->fillForm(studentFormData(['unit_id' => $this->units['SD'], 'cohort_id' => $this->sltpCohort->id]))
        ->call('create')
        ->assertHasFormErrors(['cohort_id']);

    expect(Student::query()->count())->toBe(0);
});

it('rejects a duplicate nis on create', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    Student::factory()->create(['nis' => '2026001']);

    Livewire::test(CreateStudent::class)
        ->fillForm(studentFormData(['unit_id' => $this->units['SD'], 'cohort_id' => $this->sdCohort->id]))
        ->call('create')
        ->assertHasFormErrors(['nis' => 'unique']);
});

it('does not change the unit from the edit form', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    $student = Student::factory()->forCohort($this->sdCohort)->create();

    Livewire::test(EditStudent::class, ['record' => $student->id])
        ->fillForm(['name' => 'Nama Baru', 'unit_id' => $this->units['SLTP'], 'cohort_id' => $this->sltpCohort->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($student->fresh())
        ->name->toBe('Nama Baru')
        ->unit_id->toBe($this->units['SD']);
});

it('moves a student to another unit with the change unit action', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    $student = Student::factory()->forCohort($this->sdCohort)->create();

    Livewire::test(EditStudent::class, ['record' => $student->id])
        ->callAction('changeUnit', ['unit_id' => $this->units['SLTP'], 'cohort_id' => $this->sltpCohort->id])
        ->assertNotified();

    expect($student->fresh())
        ->unit_id->toBe($this->units['SLTP'])
        ->cohort_id->toBe($this->sltpCohort->id);
});

it('hides the change unit action from a read-only role', function () {
    $this->actingAs(User::factory()->create()->assignRole('kesantrian'));
    $student = Student::factory()->forCohort($this->sdCohort)->create();

    Livewire::test(ListStudents::class)
        ->assertActionHidden(TestAction::make('changeUnit')->table($student));
});

it('AC-37: shows the active room or nonresident in the student table', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    $room = asramaRoom(4, 'L', ['name' => 'Kamar 101'], asramaDormitory('L', ['name' => 'Asrama Abu Bakar']));
    $resident = Student::factory()->forCohort($this->sdCohort)->create(['gender' => 'L']);
    $nonResident = Student::factory()->forCohort($this->sdCohort)->create(['gender' => 'L']);
    asramaResident($resident, $room);

    Livewire::test(ListStudents::class)
        ->assertCanSeeTableRecords([$resident, $nonResident])
        ->assertSeeText('Asrama Abu Bakar')
        ->assertSeeText('Kamar 101')
        ->assertSeeText(__('pondok.room_assignment.non_resident'));
});

it('AC-37: hides the room column when the asrama module is disabled', function () {
    Setting::set('modules.asrama.enabled', false);
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    $student = Student::factory()->forCohort($this->sdCohort)->create(['gender' => 'L']);
    asramaResident($student, asramaRoom(4, 'L', ['name' => 'Kamar 101']));

    Livewire::test(ListStudents::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$student])
        ->assertDontSeeText('Kamar 101')
        ->assertDontSeeText(__('pondok.room_assignment.non_resident'));
});

it('AC-37: does not query room assignments when the asrama module is disabled', function () {
    Setting::set('modules.asrama.enabled', false);
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
    $student = Student::factory()->forCohort($this->sdCohort)->create(['gender' => 'L']);
    asramaResident($student, asramaRoom(4, 'L'));

    DB::enableQueryLog();
    Livewire::test(ListStudents::class)->assertCanSeeTableRecords([$student]);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'room_assignments'));

    expect($queries)->toBeEmpty();
});

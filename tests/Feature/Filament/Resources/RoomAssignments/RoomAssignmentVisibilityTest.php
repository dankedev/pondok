<?php

use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->year = AcademicYear::factory()->active()->create();
    $this->dormitoryA = asramaDormitory('L');
    $this->dormitoryB = asramaDormitory('L');
    $this->roomA1 = asramaRoom(5, 'L', [], $this->dormitoryA);
    $this->roomA2 = asramaRoom(5, 'L', [], $this->dormitoryA);
    $this->roomB1 = asramaRoom(5, 'L', [], $this->dormitoryB);
    $this->residentA1 = asramaResident(asramaStudent(), $this->roomA1);
    $this->residentA2 = asramaResident(asramaStudent(), $this->roomA2);
    $this->residentB1 = asramaResident(asramaStudent(), $this->roomB1);
    $this->musyrif = asramaUser('musyrif');
    $this->staff = Staff::factory()->create(['user_id' => $this->musyrif->id]);
});

function asramaSupervision(Staff $staff, Dormitory $dormitory, ?Room $room, AcademicYear $year, array $attributes = []): DormitorySupervisor
{
    return DormitorySupervisor::factory()->create([
        'staff_id' => $staff->id,
        'dormitory_id' => $dormitory->id,
        'room_id' => $room?->id,
        'academic_year_id' => $year->id,
        'role' => 'musyrif',
        'start_on' => '2026-07-15',
        'end_on' => null,
        ...$attributes,
    ]);
}

it('AC-30: shows musyrif only residents of supervised dormitories', function () {
    asramaSupervision($this->staff, $this->dormitoryA, null, $this->year);

    $this->actingAs($this->musyrif);

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$this->residentA1, $this->residentA2])
        ->assertCanNotSeeTableRecords([$this->residentB1]);
});

it('AC-30: shows musyrif only residents of a supervised room', function () {
    asramaSupervision($this->staff, $this->dormitoryA, $this->roomA1, $this->year);

    $this->actingAs($this->musyrif);

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$this->residentA1])
        ->assertCanNotSeeTableRecords([$this->residentA2, $this->residentB1]);
});

it('AC-30: ignores ended or past-year supervisions', function () {
    asramaSupervision($this->staff, $this->dormitoryA, null, $this->year, ['end_on' => '2026-09-01']);
    asramaSupervision($this->staff, $this->dormitoryB, null, AcademicYear::factory()->create());

    $this->actingAs($this->musyrif);

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanNotSeeTableRecords([$this->residentA1, $this->residentA2, $this->residentB1])
        ->assertCountTableRecords(0);
});

it('AC-30: shows nothing to a musyrif without staff', function () {
    asramaSupervision($this->staff, $this->dormitoryA, null, $this->year);

    $this->actingAs(asramaUser('musyrif'));

    Livewire::test(ManageRoomAssignments::class)
        ->assertSuccessful()
        ->assertCountTableRecords(0);
});

it('AC-30: shows kesantrian every dormitory', function () {
    $this->actingAs(asramaUser('kesantrian'));

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$this->residentA1, $this->residentA2, $this->residentB1]);
});

it('AC-31: limits residents and student options to the user units', function () {
    $units = Unit::query()->pluck('id', 'code');
    $sltpStudent = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units['SLTP']]))->create(['gender' => 'L']);
    $sdStudent = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units['SD']]))->create(['gender' => 'L']);
    $sltpResident = asramaResident($sltpStudent, $this->roomA1);
    $sdResident = asramaResident($sdStudent, $this->roomA2);
    $sdCandidate = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units['SD']]))->create(['gender' => 'L']);

    $this->actingAs(asramaUser('kesantrian', ['SLTP']));

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$sltpResident])
        ->assertCanNotSeeTableRecords([$sdResident, $this->residentA1])
        ->callAction('assign', [
            'student_id' => $sdCandidate->id,
            'dormitory_id' => $this->dormitoryB->id,
            'room_id' => $this->roomB1->id,
            'start_on' => '2026-10-01',
        ])
        ->assertHasFormErrors(['student_id']);

    expect($sdCandidate->fresh()->isResident())->toBeFalse();
});
